<?php
/**
 * Tiny router: maps METHOD + path pattern to a handler, with middleware.
 *
 *   $router->get('/orders/{ref}', [Orders::class, 'show'], [Auth::student()]);
 *
 * Middleware and handlers receive the Request. Handlers return an array
 * (sent as JSON with status 200) or [$data, $status].
 */
final class Router
{
    private array $routes = [];
    private array $globalMiddleware = [];

    public function use(callable $middleware): void
    {
        $this->globalMiddleware[] = $middleware;
    }

    public function get(string $pattern, $handler, array $middleware = []): void { $this->add('GET', $pattern, $handler, $middleware); }
    public function post(string $pattern, $handler, array $middleware = []): void { $this->add('POST', $pattern, $handler, $middleware); }
    public function patch(string $pattern, $handler, array $middleware = []): void { $this->add('PATCH', $pattern, $handler, $middleware); }
    public function put(string $pattern, $handler, array $middleware = []): void { $this->add('PUT', $pattern, $handler, $middleware); }
    public function delete(string $pattern, $handler, array $middleware = []): void { $this->add('DELETE', $pattern, $handler, $middleware); }

    public function add(string $method, string $pattern, $handler, array $middleware = []): void
    {
        $regex = '#^' . preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', rtrim($pattern, '/') ?: '/') . '$#';
        $this->routes[] = compact('method', 'pattern', 'regex', 'handler', 'middleware');
    }

    public function routes(): array
    {
        return array_map(fn($r) => ['method' => $r['method'], 'path' => $r['pattern']], $this->routes);
    }

    /** Find the matching route, run middleware + handler, return [data, status]. */
    public function dispatch(Request $request): array
    {
        if ($request->method === 'OPTIONS') {
            return [null, 204];
        }
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }
            $request->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            foreach (array_merge($this->globalMiddleware, $route['middleware']) as $mw) {
                $mw($request);
            }
            $result = call_user_func($route['handler'], $request);
            if (is_array($result) && count($result) === 2 && array_keys($result) === [0, 1] && is_int($result[1])) {
                return $result;
            }
            return [$result, 200];
        }
        if ($pathMatched) {
            throw new HttpError(405, 'method_not_allowed', "Method {$request->method} is not allowed here.");
        }
        throw new HttpError(404, 'not_found', "No API endpoint at {$request->method} {$request->path}.");
    }
}
