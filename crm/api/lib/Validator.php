<?php
/**
 * Input validation.
 *
 *   $data = Validator::check($request->body(), [
 *       'name'  => 'required|string|max:80',
 *       'price' => 'required|number|min:0',
 *       'type'  => 'in:menu,addon',
 *   ]);
 *
 * Returns only the listed fields (cast to the right type). Throws a 422
 * HttpError with a message per field when something is wrong.
 */
final class Validator
{
    public static function check(array $input, array $rules): array
    {
        $clean = [];
        $errors = [];
        foreach ($rules as $field => $ruleString) {
            $rulesList = explode('|', $ruleString);
            $present = array_key_exists($field, $input) && $input[$field] !== null && $input[$field] !== '';
            if (!$present) {
                if (in_array('required', $rulesList, true)) {
                    $errors[$field] = 'This field is required.';
                }
                continue;
            }
            $value = $input[$field];
            foreach ($rulesList as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $error = self::apply($name, $arg, $value);
                if ($error !== null) {
                    $errors[$field] = $error;
                    break;
                }
            }
            if (!isset($errors[$field])) {
                $clean[$field] = $value;
            }
        }
        if ($errors) {
            $first = reset($errors);
            throw new HttpError(422, 'validation_error', 'Please check the highlighted fields: ' . key($errors) . ' — ' . $first, $errors);
        }
        return $clean;
    }

    /** Validates (and casts) one value. Returns an error message or null. */
    private static function apply(string $rule, ?string $arg, &$value): ?string
    {
        switch ($rule) {
            case 'required':
                return null;
            case 'string':
                if (!is_scalar($value)) return 'Must be text.';
                $value = trim((string) $value);
                return null;
            case 'int':
                if (filter_var($value, FILTER_VALIDATE_INT) === false) return 'Must be a whole number.';
                $value = (int) $value;
                return null;
            case 'number':
                if (!is_numeric($value)) return 'Must be a number.';
                $value = $value + 0;
                return null;
            case 'bool':
                $b = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($b === null) return 'Must be true or false.';
                $value = $b;
                return null;
            case 'array':
                return is_array($value) ? null : 'Must be a list.';
            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Must be a valid email address.';
            case 'date':
                $d = DateTime::createFromFormat('Y-m-d', (string) $value);
                return $d && $d->format('Y-m-d') === $value ? null : 'Must be a date like 2026-11-28.';
            case 'time':
                if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', (string) $value, $m)) return 'Must be a time like 12:30.';
                $value = sprintf('%02d:%02d', $m[1], $m[2]);
                return null;
            case 'min':
                if (is_numeric($value)) return $value < $arg ? "Must be at least $arg." : null;
                return mb_strlen((string) $value) < (int) $arg ? "Must be at least $arg characters." : null;
            case 'max':
                if (is_numeric($value) && !is_string($value)) return $value > $arg ? "Must be at most $arg." : null;
                return mb_strlen((string) $value) > (int) $arg ? "Must be at most $arg characters." : null;
            case 'in':
                return in_array((string) $value, explode(',', $arg), true) ? null : 'Must be one of: ' . str_replace(',', ', ', $arg) . '.';
            case 'regex':
                return preg_match($arg, (string) $value) ? null : 'Has the wrong format.';
            default:
                return null;
        }
    }
}
