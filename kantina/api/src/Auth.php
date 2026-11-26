<?php
/**
 * Sign-in, tokens and role-based access.
 *
 * Every protected route uses one of the middleware functions:
 *   Auth::student()  students only
 *   Auth::staff()    stall staff (and admins)
 *   Auth::admin()    canteen admin only
 *   Auth::any()      any signed-in user
 * They read the Bearer token, check it, load the user into $request->user,
 * and stop with 401 (not signed in) or 403 (wrong role).
 */
final class Auth
{
    private const MAX_FAILURES = 5;
    private const LOCK_MINUTES = 10;

    public static function routes(Router $r): void
    {
        $r->post('/auth/login', [self::class, 'login']);
        $r->post('/auth/register', [self::class, 'register']);
        $r->post('/auth/staff/login', [self::class, 'staffLogin']);
        $r->get('/auth/me', fn(Request $q) => ['user' => self::format($q->user)], [self::any()]);
    }

    // ------------------------------------------------------------- middleware
    public static function student(): callable { return self::guard(['student']); }
    public static function staff(): callable { return self::guard(['staff', 'admin']); }
    public static function admin(): callable { return self::guard(['admin']); }
    public static function any(): callable { return self::guard(['student', 'staff', 'admin']); }

    private static function guard(array $roles): callable
    {
        return function (Request $req) use ($roles): void {
            $token = $req->bearerToken();
            if (!$token) {
                throw new HttpError(401, 'unauthenticated', 'Please sign in first.');
            }
            $claims = Jwt::decode($token, (string) Config::get('jwt_secret'));
            if (!$claims || empty($claims['sub'])) {
                throw new HttpError(401, 'invalid_token', 'Your session has expired. Please sign in again.');
            }
            $user = Db::one('SELECT * FROM users WHERE id = ?', [(int) $claims['sub']]);
            if (!$user) {
                throw new HttpError(401, 'invalid_token', 'This account no longer exists.');
            }
            if (!in_array($user['role'], $roles, true)) {
                throw new HttpError(403, 'forbidden', 'You do not have access to this.');
            }
            $req->user = $user;
        };
    }

    // ------------------------------------------------------------- handlers
    public static function login(Request $req): array
    {
        $body = $req->body();
        $login = trim((string) ($body['login'] ?? $body['student_id'] ?? $body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($login === '' || $password === '') {
            throw new HttpError(422, 'validation_error', 'Enter your student ID (or email) and password.', [
                'login' => $login === '' ? 'Required.' : null,
                'password' => $password === '' ? 'Required.' : null,
            ]);
        }
        self::checkThrottle($req, $login);
        $user = Db::one("SELECT * FROM users WHERE role = 'student' AND (student_id = ? OR email = ?)", [$login, strtolower($login)]);
        return self::finishLogin($req, $login, $user, $password, 'Wrong student ID or password.');
    }

    public static function staffLogin(Request $req): array
    {
        $body = $req->body();
        $account = strtolower(trim((string) ($body['account'] ?? $body['login'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        if ($account === '' || $password === '') {
            throw new HttpError(422, 'validation_error', 'Enter your store account and password.');
        }
        self::checkThrottle($req, $account);
        $user = Db::one("SELECT * FROM users WHERE role IN ('staff', 'admin') AND account = ?", [$account]);
        return self::finishLogin($req, $account, $user, $password, 'Wrong store account or password.');
    }

    public static function register(Request $req): array
    {
        $d = Validator::check($req->body(), [
            'student_id' => 'required|string|regex:/^\d{2}-\d{4,5}$/',
            'name' => 'required|string|min:2|max:120',
            'email' => 'required|email|max:120',
            'password' => 'required|string|min:8|max:72',
            'program' => 'string|max:60',
            'grad_year' => 'int|min:2020|max:2040',
        ]);
        $d['email'] = strtolower($d['email']);
        if (Db::value('SELECT 1 FROM users WHERE student_id = ? OR email = ?', [$d['student_id'], $d['email']])) {
            throw new HttpError(409, 'account_exists', 'An account with this student ID or email already exists.');
        }
        $id = Db::insert('users', [
            'role' => 'student',
            'student_id' => $d['student_id'],
            'email' => $d['email'],
            'name' => $d['name'],
            'program' => $d['program'] ?? null,
            'grad_year' => $d['grad_year'] ?? null,
            'password_hash' => password_hash($d['password'], PASSWORD_DEFAULT),
        ]);
        $user = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
        return [['token' => self::token($user), 'user' => self::format($user)], 201];
    }

    // ------------------------------------------------------------- helpers
    private static function finishLogin(Request $req, string $login, ?array $user, string $password, string $error): array
    {
        if (!$user || !password_verify($password, $user['password_hash'])) {
            self::recordFailure($req, $login);
            throw new HttpError(401, 'invalid_credentials', $error);
        }
        Support::kvDelete(self::throttleKey($req, $login));
        return ['token' => self::token($user), 'user' => self::format($user)];
    }

    public static function token(array $user): string
    {
        return Jwt::encode(
            ['sub' => (int) $user['id'], 'role' => $user['role'], 'stall' => $user['stall_id']],
            (string) Config::get('jwt_secret'),
            (int) Config::get('token_ttl_hours', 168) * 3600
        );
    }

    public static function format(array $u): array
    {
        $out = [
            'id' => (int) $u['id'],
            'role' => $u['role'],
            'name' => $u['name'],
            'first_name' => explode(' ', trim($u['name']))[0],
            'initials' => Support::initials($u['name']),
        ];
        if ($u['role'] === 'student') {
            $out += [
                'student_id' => $u['student_id'],
                'email' => $u['email'],
                'program' => $u['program'],
                'grad_year' => $u['grad_year'] !== null ? (int) $u['grad_year'] : null,
                'notify_ready' => (bool) (int) $u['notify_ready'],
            ];
        } else {
            $stall = $u['stall_id'] ? Db::one('SELECT id, name FROM stalls WHERE id = ?', [$u['stall_id']]) : null;
            $out += ['account' => $u['account'], 'stall' => $stall];
        }
        return $out;
    }

    /** Stall id of the signed-in staff member (admins must pass ?stall=). */
    public static function stallOf(Request $req): string
    {
        $stall = $req->user['stall_id'] ?? null;
        if (!$stall && $req->user['role'] === 'admin') {
            $stall = $req->query['stall'] ?? null;
        }
        if (!$stall || !Db::value('SELECT 1 FROM stalls WHERE id = ?', [$stall])) {
            throw new HttpError(400, 'no_stall', 'This account is not linked to a stall.');
        }
        return $stall;
    }

    // Brute-force protection: 5 wrong passwords lock that login for 10 minutes (per IP).
    private static function throttleKey(Request $req, string $login): string
    {
        return 'login:' . substr(sha1($req->ip() . '|' . $login), 0, 40);
    }

    private static function checkThrottle(Request $req, string $login): void
    {
        $state = Support::kvGet(self::throttleKey($req, $login));
        if ($state && $state['count'] >= self::MAX_FAILURES && $state['until'] > time()) {
            $minutes = (int) ceil(($state['until'] - time()) / 60);
            throw new HttpError(429, 'too_many_attempts', "Too many wrong passwords. Try again in $minutes minute(s).");
        }
    }

    private static function recordFailure(Request $req, string $login): void
    {
        $key = self::throttleKey($req, $login);
        $state = Support::kvGet($key);
        if (!$state || $state['until'] < time()) {
            $state = ['count' => 0, 'until' => 0];
        }
        $state['count']++;
        $state['until'] = time() + self::LOCK_MINUTES * 60;
        Support::kvSet($key, $state);
    }
}
