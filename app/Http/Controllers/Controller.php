<?php

declare(strict_types=1);

namespace MTL\Http\Controllers;

use MTL\Auth\AuthManager;
use MTL\Core\HttpException;
use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Core\Session;
use MTL\Models\Model;
use MTL\Models\User;
use MTL\Support\Validator;

defined('MTL_APP') || exit;

/**
 * Shared behaviour for controllers: the current user, authorisation checks,
 * validation and the redirect helpers.
 */
abstract class Controller
{
    protected function auth(): AuthManager
    {
        return AuthManager::instance();
    }

    protected function user(): ?User
    {
        return $this->auth()->user();
    }

    /**
     * The signed-in user, or a 401 if there is none. For controllers behind
     * the auth middleware, where a null user would be a programming error.
     */
    protected function requireUser(): User
    {
        $user = $this->user();

        if ($user === null) {
            throw HttpException::unauthorized();
        }

        return $user;
    }

    /**
     * Aborts with 403 unless the current user holds $permission over $subject.
     */
    protected function authorize(string $permission, mixed $subject = null): void
    {
        if (!$this->auth()->can($permission, $subject)) {
            throw HttpException::forbidden();
        }
    }

    /**
     * Validates request input against $rules and returns the cleaned values.
     *
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     *
     * @return array<string,mixed>
     */
    protected function validate(Request $request, array $rules, array $labels = []): array
    {
        return Validator::forRequest($request, $rules)->labels($labels)->validated();
    }

    /**
     * Loads a record or aborts with 404.
     *
     * @template T of Model
     *
     * @param class-string<T> $model
     *
     * @return T
     */
    protected function findOrFail(string $model, int|string $id, bool $includeDeleted = false): Model
    {
        /** @var Model|null $record */
        $record = is_string($id) && !ctype_digit($id)
            ? $model::findByUuid($id)
            : $model::find((int) $id);

        if ($record === null || (!$includeDeleted && $record->isDeleted())) {
            throw HttpException::notFound();
        }

        return $record;
    }

    /**
     * Redirect carrying a success notification.
     */
    protected function back(string $to, string $message = '', string $type = 'positive'): Response
    {
        if ($message !== '') {
            Session::notify($type, $message);
        }

        // 303 rather than 302 after a write: it tells the browser to follow up
        // with GET, which is what stops a refresh from re-submitting the form.
        return Response::redirect($to, 303);
    }

    /**
     * Returns to wherever the visitor came from, falling back to $fallback.
     */
    protected function backToPrevious(string $fallback = '/', string $message = '', string $type = 'positive'): Response
    {
        $previous = Session::get('_previous_url');

        return $this->back(
            is_string($previous) && $previous !== '' ? $previous : path($fallback),
            $message,
            $type
        );
    }

    /**
     * Answers with JSON for a fetch() caller and a redirect for a form post,
     * so the same controller action serves both.
     *
     * @param array<string,mixed> $payload
     */
    protected function respond(Request $request, array $payload, string $redirectTo, string $message = ''): Response
    {
        if ($request->wantsJson()) {
            return Response::json($payload + ['ok' => true, 'message' => $message]);
        }

        return $this->back($redirectTo, $message);
    }

    /**
     * The page number from ?page=, clamped to something sane.
     */
    protected function page(Request $request): int
    {
        return max(1, min(10000, $request->int('page', 1)));
    }
}
