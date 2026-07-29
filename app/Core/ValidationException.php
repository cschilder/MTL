<?php

declare(strict_types=1);

namespace MTL\Core;

defined('MTL_APP') || exit;

/**
 * Raised when input fails validation.
 *
 * The Application error handler turns this into a redirect back to the form
 * with the messages and the submitted values flashed, or a 422 JSON payload
 * for API clients.
 */
final class ValidationException extends HttpException
{
    /**
     * @param array<string,list<string>> $errors field name => messages
     * @param array<string,mixed>        $input  values to repopulate the form with
     */
    public function __construct(
        private readonly array $errors,
        private readonly array $input = [],
        string $message = 'The submitted data is not valid.',
    ) {
        parent::__construct(422, $message);
    }

    /** @return array<string,list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string,mixed> */
    public function input(): array
    {
        return $this->input;
    }

    public function first(): string
    {
        foreach ($this->errors as $messages) {
            if ($messages !== []) {
                return $messages[0];
            }
        }

        return $this->getMessage();
    }
}
