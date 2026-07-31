<?php

declare(strict_types=1);

namespace MTL\Support;

use MTL\Core\Database;
use MTL\Core\Request;
use MTL\Core\ValidationException;

defined('MTL_APP') || exit;

/**
 * Rule-based input validation.
 *
 * Rules are declared per field as a pipe-separated string:
 *
 *     $data = Validator::make($request->all(), [
 *         'title' => 'required|string|max:180',
 *         'lat'   => 'nullable|numeric|between:-90,90',
 *         'email' => 'required|email|unique:users,email',
 *     ])->validated();
 *
 * validated() returns only the fields that were declared, with values cast to
 * the type the rules imply. Anything the client sent that was not declared is
 * dropped, so a hidden form field can never reach an UPDATE statement.
 */
final class Validator
{
    /** @var array<string,list<string>> */
    private array $errors = [];

    /** @var array<string,mixed> */
    private array $clean = [];

    /** @var array<string,string> field => human label used in messages */
    private array $labels = [];

    /**
     * @param array<string,mixed>  $data
     * @param array<string,string> $rules
     */
    private function __construct(
        private readonly array $data,
        private readonly array $rules,
    ) {
    }

    /**
     * @param array<string,mixed>  $data
     * @param array<string,string> $rules
     */
    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    /**
     * @param array<string,string> $rules
     */
    public static function forRequest(Request $request, array $rules): self
    {
        return new self($request->all(), $rules);
    }

    /**
     * @param array<string,string> $labels field => label
     */
    public function labels(array $labels): self
    {
        $this->labels = $labels;

        return $this;
    }

    /**
     * Runs the rules and returns the cleaned data, throwing on the first
     * complete pass that produced any error.
     *
     * @return array<string,mixed>
     */
    public function validated(): array
    {
        $this->run();

        if ($this->errors !== []) {
            throw new ValidationException($this->errors, $this->forgetSecrets($this->data));
        }

        return $this->clean;
    }

    /** @return array<string,list<string>> */
    public function errors(): array
    {
        $this->run();

        return $this->errors;
    }

    public function passes(): bool
    {
        return $this->errors() === [];
    }

    /** @return array<string,mixed> */
    public function clean(): array
    {
        $this->run();

        return $this->clean;
    }

    private bool $ran = false;

    private function run(): void
    {
        if ($this->ran) {
            return;
        }
        $this->ran = true;

        foreach ($this->rules as $field => $ruleString) {
            $rules = array_filter(explode('|', $ruleString));
            $value = $this->data[$field] ?? null;

            if (is_string($value)) {
                $value = Str::normaliseText($value);
                // 'trim' is on by default; a rule can opt out with |raw.
                if (!in_array('raw', $rules, true)) {
                    $value = trim($value);
                }
            }

            $nullable = in_array('nullable', $rules, true);
            $required = in_array('required', $rules, true);
            $isEmpty = $value === null || $value === '' || $value === [];

            if ($isEmpty) {
                if ($required) {
                    $this->fail($field, __('validation.required', ['field' => $this->label($field)]));
                    continue;
                }

                if ($nullable) {
                    $this->clean[$field] = null;
                    continue;
                }

                // Not required and not nullable: only keep it if the client
                // actually sent the key, so a partial update stays partial.
                if (array_key_exists($field, $this->data)) {
                    $this->clean[$field] = $value === null ? '' : $value;
                }
                continue;
            }

            $accepted = true;

            foreach ($rules as $rule) {
                [$name, $argument] = array_pad(explode(':', $rule, 2), 2, null);

                $result = $this->applyRule($name, $field, $value, $argument);

                if ($result === RuleOutcome::Failed) {
                    $accepted = false;
                    break;
                }

                // Anything that is not one of the two signals is a coerced value
                // — an int, a float, a normalised date, or a real boolean. It has
                // to be tested this way round: `false` is a value a rule returns,
                // not a way of saying the rule failed.
                if ($result !== RuleOutcome::Passed) {
                    $value = $result;
                }
            }

            if ($accepted) {
                $this->clean[$field] = $value;
            }
        }
    }

    private function applyRule(string $name, string $field, mixed $value, ?string $argument): mixed
    {
        $label = $this->label($field);

        return match ($name) {
            'required', 'nullable', 'raw', 'sometimes' => RuleOutcome::Passed,

            'string' => is_scalar($value)
                ? (string) $value
                : $this->fail($field, __('validation.string', ['field' => $label])),

            'int', 'integer' => $this->validateInt($field, $value, $label),

            'numeric' => is_numeric($value)
                ? (float) $value
                : $this->fail($field, __('validation.numeric', ['field' => $label])),

            'bool', 'boolean' => in_array(
                is_string($value) ? strtolower($value) : $value,
                [true, false, 1, 0, '1', '0', 'true', 'false', 'on', 'off', 'yes', 'no'],
                true
            )
                ? in_array(is_string($value) ? strtolower($value) : $value, [true, 1, '1', 'true', 'on', 'yes'], true)
                : $this->fail($field, __('validation.boolean', ['field' => $label])),

            'email' => $this->validateEmail($field, $value, $label),

            'url' => filter_var((string) $value, FILTER_VALIDATE_URL) !== false
                && preg_match('#^https?://#i', (string) $value) === 1
                    ? (string) $value
                    : $this->fail($field, __('validation.url', ['field' => $label])),

            'slug' => preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $value) === 1
                ? (string) $value
                : $this->fail($field, __('validation.slug', ['field' => $label])),

            'date' => $this->validateDate($field, $value, $label),

            'min' => $this->validateMin($field, $value, (string) $argument, $label),

            'max' => $this->validateMax($field, $value, (string) $argument, $label),

            'between' => $this->validateBetween($field, $value, (string) $argument, $label),

            'in' => in_array((string) $value, explode(',', (string) $argument), true)
                ? RuleOutcome::Passed
                : $this->fail($field, __('validation.in', ['field' => $label])),

            'regex' => preg_match('#' . $argument . '#u', (string) $value) === 1
                ? RuleOutcome::Passed
                : $this->fail($field, __('validation.regex', ['field' => $label])),

            'array' => is_array($value)
                ? RuleOutcome::Passed
                : $this->fail($field, __('validation.array', ['field' => $label])),

            'confirmed' => (string) $value === (string) ($this->data[$field . '_confirmation'] ?? '')
                ? RuleOutcome::Passed
                : $this->fail($field, __('validation.confirmed', ['field' => $label])),

            'same' => (string) $value === (string) ($this->data[(string) $argument] ?? '')
                ? RuleOutcome::Passed
                : $this->fail($field, __('validation.same', ['field' => $label, 'other' => $this->label((string) $argument)])),

            'unique' => $this->validateUnique($field, $value, (string) $argument, $label),

            'exists' => $this->validateExists($field, $value, (string) $argument, $label),

            'password' => $this->validatePassword($field, (string) $value),

            'hex' => preg_match('/^[0-9a-fA-F]+$/', (string) $value) === 1
                ? RuleOutcome::Passed
                : $this->fail($field, __('validation.hex', ['field' => $label])),

            'latitude' => is_numeric($value) && (float) $value >= -90 && (float) $value <= 90
                ? (float) $value
                : $this->fail($field, __('validation.latitude', ['field' => $label])),

            'longitude' => is_numeric($value) && (float) $value >= -180 && (float) $value <= 180
                ? (float) $value
                : $this->fail($field, __('validation.longitude', ['field' => $label])),

            'timezone' => in_array((string) $value, \DateTimeZone::listIdentifiers(), true)
                ? RuleOutcome::Passed
                : $this->fail($field, __('validation.timezone', ['field' => $label])),

            default => throw new \InvalidArgumentException('Unknown validation rule: ' . $name),
        };
    }

    private function validateInt(string $field, mixed $value, string $label): mixed
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        return $this->fail($field, __('validation.integer', ['field' => $label]));
    }

    private function validateEmail(string $field, mixed $value, string $label): mixed
    {
        $email = strtolower(trim((string) $value));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            return $this->fail($field, __('validation.email', ['field' => $label]));
        }

        return $email;
    }

    private function validateDate(string $field, mixed $value, string $label): mixed
    {
        $raw = trim((string) $value);

        // Accept the shapes an <input type="date"> or "datetime-local" sends,
        // plus a full ISO-8601 timestamp from the API.
        foreach (['Y-m-d', 'Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d\TH:i', 'Y-m-d\TH:i:s', \DateTimeInterface::ATOM] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $raw, new \DateTimeZone('UTC'));
            $parsedErrors = \DateTimeImmutable::getLastErrors();

            if ($parsed !== false && ($parsedErrors === false || ($parsedErrors['warning_count'] ?? 0) === 0)) {
                // A date-only value has no meaningful time; pin it to midnight
                // so comparisons are stable.
                if ($format === 'Y-m-d') {
                    $parsed = $parsed->setTime(0, 0);
                }

                return $parsed->format('Y-m-d H:i:s');
            }
        }

        return $this->fail($field, __('validation.date', ['field' => $label]));
    }

    private function validateMin(string $field, mixed $value, string $argument, string $label): mixed
    {
        $min = (float) $argument;

        if (is_array($value)) {
            return count($value) >= $min ? RuleOutcome::Passed : $this->fail($field, __('validation.min_items', ['field' => $label, 'min' => $argument]));
        }

        if (is_int($value) || is_float($value)) {
            return $value >= $min ? RuleOutcome::Passed : $this->fail($field, __('validation.min_value', ['field' => $label, 'min' => $argument]));
        }

        return mb_strlen((string) $value, 'UTF-8') >= $min
            ? RuleOutcome::Passed
            : $this->fail($field, __('validation.min_length', ['field' => $label, 'min' => $argument]));
    }

    private function validateMax(string $field, mixed $value, string $argument, string $label): mixed
    {
        $max = (float) $argument;

        if (is_array($value)) {
            return count($value) <= $max ? RuleOutcome::Passed : $this->fail($field, __('validation.max_items', ['field' => $label, 'max' => $argument]));
        }

        if (is_int($value) || is_float($value)) {
            return $value <= $max ? RuleOutcome::Passed : $this->fail($field, __('validation.max_value', ['field' => $label, 'max' => $argument]));
        }

        return mb_strlen((string) $value, 'UTF-8') <= $max
            ? RuleOutcome::Passed
            : $this->fail($field, __('validation.max_length', ['field' => $label, 'max' => $argument]));
    }

    private function validateBetween(string $field, mixed $value, string $argument, string $label): mixed
    {
        [$min, $max] = array_pad(explode(',', $argument), 2, '0');

        if (!is_numeric($value)) {
            return $this->fail($field, __('validation.numeric', ['field' => $label]));
        }

        $number = (float) $value;

        return $number >= (float) $min && $number <= (float) $max
            ? $number
            : $this->fail($field, __('validation.between', ['field' => $label, 'min' => $min, 'max' => $max]));
    }

    /**
     * unique:table,column[,ignoreId[,idColumn]] — the ignore form lets an edit
     * form keep its own value.
     */
    private function validateUnique(string $field, mixed $value, string $argument, string $label): mixed
    {
        [$table, $column, $ignore, $idColumn] = array_pad(explode(',', $argument), 4, null);

        $query = Database::instance()
            ->table((string) $table)
            ->where((string) ($column ?? $field), '=', $value);

        if ($ignore !== null && $ignore !== '' && $ignore !== '0') {
            $query->where($idColumn ?? 'id', '!=', $ignore);
        }

        return $query->exists()
            ? $this->fail($field, __('validation.unique', ['field' => $label]))
            : RuleOutcome::Passed;
    }

    private function validateExists(string $field, mixed $value, string $argument, string $label): mixed
    {
        [$table, $column] = array_pad(explode(',', $argument), 2, 'id');

        return Database::instance()->table((string) $table)->where((string) $column, '=', $value)->exists()
            ? RuleOutcome::Passed
            : $this->fail($field, __('validation.exists', ['field' => $label]));
    }

    /**
     * Password strength. Length does most of the work; the rest rejects the
     * handful of shapes that are long but still trivial.
     */
    private function validatePassword(string $field, string $value): mixed
    {
        $length = mb_strlen($value, 'UTF-8');

        if ($length < 10) {
            return $this->fail($field, __('validation.password_short'));
        }

        if ($length > 200) {
            return $this->fail($field, __('validation.password_long'));
        }

        // A single repeated character, or a straight run off the keyboard.
        if (preg_match('/^(.)\1+$/u', $value) === 1) {
            return $this->fail($field, __('validation.password_weak'));
        }

        $lower = strtolower($value);
        foreach (['password', 'wachtwoord', '1234567890', 'qwertyuiop', 'letmein', 'welkom01', 'iloveyou'] as $common) {
            if (str_contains($lower, $common)) {
                return $this->fail($field, __('validation.password_weak'));
            }
        }

        // Passed, and deliberately not coerced: the plaintext is handed on
        // unchanged for hashing.
        return RuleOutcome::Passed;
    }

    private function fail(string $field, string $message): RuleOutcome
    {
        $this->errors[$field][] = $message;

        return RuleOutcome::Failed;
    }

    private function label(string $field): string
    {
        if (isset($this->labels[$field])) {
            return $this->labels[$field];
        }

        $translated = __('field.' . $field);

        return $translated === 'field.' . $field
            ? str_replace('_', ' ', $field)
            : $translated;
    }

    /**
     * Values that must never be flashed back into a form.
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function forgetSecrets(array $data): array
    {
        foreach (array_keys($data) as $key) {
            $lower = strtolower((string) $key);
            if (str_contains($lower, 'password') || str_contains($lower, 'token') || str_contains($lower, 'secret')) {
                unset($data[$key]);
            }
        }

        return $data;
    }
}
