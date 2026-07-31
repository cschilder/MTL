<?php

declare(strict_types=1);

namespace MTL\Core;

use MTL\Auth\AuthManager;
use MTL\Http\Middleware\AuthenticateMiddleware;
use MTL\Http\Middleware\CsrfMiddleware;
use MTL\Http\Middleware\GuestMiddleware;
use MTL\Http\Middleware\PermissionMiddleware;
use MTL\Http\Middleware\SecurityHeadersMiddleware;
use MTL\Http\Middleware\ThrottleMiddleware;
use MTL\Services\SettingsService;

defined('MTL_APP') || exit;

/**
 * Wires the application together and runs one request.
 */
final class Application
{
    private static ?self $instance = null;

    private Router $router;

    private bool $installed = true;

    private function __construct(public readonly string $root)
    {
        $this->router = new Router();
    }

    public static function boot(string $root): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $app = self::$instance = new self($root);

        $app->loadConfiguration();
        $app->registerErrorHandling();
        $app->configureRuntime();
        $app->registerRoutes();

        return $app;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Application has not been booted.');
        }

        return self::$instance;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function isInstalled(): bool
    {
        return $this->installed;
    }

    // -------------------------------------------------------------------------
    // Boot steps
    // -------------------------------------------------------------------------

    private function loadConfiguration(): void
    {
        $file = $this->root . '/config/config.php';

        if (is_file($file)) {
            Config::load($file);

            return;
        }

        // No config yet: fall back to the template so the installer can render
        // and tell the operator what to do, rather than dying with a stack
        // trace on a fresh upload.
        $this->installed = false;
        Config::load($this->root . '/config/config.example.php');
        Config::set(['app' => ['url' => $this->guessBaseUrl()]]);
    }

    private function guessBaseUrl(): string
    {
        $scheme = (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        // HTTP_HOST is attacker-controlled; keep it to characters that can
        // appear in a hostname so it cannot be used to inject a header.
        $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', $host) ?? 'localhost';

        return $scheme . '://' . $host;
    }

    private function registerErrorHandling(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', Config::isDebug() ? '1' : '0');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            // Promote notices and warnings to exceptions so a typo in an array
            // key surfaces during development instead of producing a subtly
            // wrong page.
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (\Throwable $e): void {
            $this->renderThrowable($e, Request::current())->send();
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();

            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            Logger::instance()->critical('Fatal error', [
                'message' => $error['message'],
                'file'    => $error['file'],
                'line'    => $error['line'],
            ]);

            if (!headers_sent()) {
                http_response_code(500);
            }
        });
    }

    private function configureRuntime(): void
    {
        date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));

        if (!extension_loaded('mbstring')) {
            throw new \RuntimeException('The mbstring extension is required.');
        }
        mb_internal_encoding('UTF-8');

        Translator::setLocale((string) Config::get('app.locale', 'nl'));
    }

    private function registerRoutes(): void
    {
        $this->router->aliasMiddleware([
            'auth'     => AuthenticateMiddleware::class,
            'guest'    => GuestMiddleware::class,
            'can'      => PermissionMiddleware::class,
            'csrf'     => CsrfMiddleware::class,
            'throttle' => ThrottleMiddleware::class,
            'headers'  => SecurityHeadersMiddleware::class,
        ]);

        $this->router->globalMiddleware(['headers', 'csrf']);

        $register = require $this->root . '/app/routes.php';
        $register($this->router);
    }

    // -------------------------------------------------------------------------
    // Request lifecycle
    // -------------------------------------------------------------------------

    public function run(): void
    {
        $request = Request::capture();

        try {
            Session::start();

            if (!$this->installed) {
                $this->renderInstallNotice()->send();

                return;
            }

            $this->prepareLocale($request);

            // Between "config exists" and "the first account exists" the whole
            // site is the installer: every other URL walks the visitor there.
            // The check is one file-exists once installation has happened, so
            // steady-state requests pay nothing for it.
            if (!\MTL\Services\InstallService::isInstalled()
                && !str_starts_with($request->path, '/install')
            ) {
                (new Response('', 302))->header('Location', path('/install'))->send();

                return;
            }

            $this->rememberPreviousUrl($request);
            $this->shareViewData($request);

            $response = $this->router->dispatch($request);
        } catch (\Throwable $e) {
            $response = $this->renderThrowable($e, $request);
        }

        // HEAD must carry the same headers as GET but no body.
        if ($request->method === 'HEAD') {
            $response = (new Response('', $response->status()))->withHeaders($response->headers());
        }

        $response->send();

        $this->afterResponse();
    }

    /**
     * Picks the interface language: an explicit user preference wins, then a
     * ?lang= override, then the browser's Accept-Language, then the default.
     */
    private function prepareLocale(Request $request): void
    {
        $available = Translator::available();
        $default = (string) Config::get('app.locale', 'nl');

        $requested = $request->string('lang');
        if ($requested !== '' && in_array($requested, $available, true)) {
            Session::put('_locale', $requested);
        }

        $stored = Session::get('_locale');
        if (is_string($stored) && in_array($stored, $available, true)) {
            Translator::setLocale($stored);

            return;
        }

        $header = $request->header('accept-language', '') ?? '';
        foreach (explode(',', $header) as $part) {
            $tag = strtolower(trim(explode(';', $part)[0]));
            $primary = substr($tag, 0, 2);

            if ($primary !== '' && in_array($primary, $available, true)) {
                Translator::setLocale($primary);

                return;
            }
        }

        Translator::setLocale($default);
    }

    /**
     * Records the last page the visitor actually looked at, which is where a
     * failed form submission redirects back to. Only navigational GET requests
     * qualify, so an image or an API poll never becomes the "previous" page.
     */
    private function rememberPreviousUrl(Request $request): void
    {
        if ($request->method !== 'GET' || $request->wantsJson()) {
            return;
        }

        if (str_starts_with($request->path, '/media/') || str_starts_with($request->path, '/assets/')) {
            return;
        }

        Session::put('_previous_url', $request->fullUrl());
    }

    private function shareViewData(Request $request): void
    {
        View::share([
            'request'       => $request,
            'currentUser'   => AuthManager::instance()->user(),
            'appName'       => (string) SettingsService::get('site.title', (string) Config::get('app.name', 'MTL')),
            'notifications' => Session::notifications(),
            'locale'        => Translator::locale(),
        ]);
    }

    private function renderInstallNotice(): Response
    {
        $html = View::render('errors/install', ['title' => 'MTL — setup required']);

        return Response::html($html, 503)->header('Retry-After', '600');
    }

    /**
     * Converts any throwable into a response the client can understand.
     */
    private function renderThrowable(\Throwable $e, ?Request $request): Response
    {
        if ($e instanceof ValidationException) {
            return $this->renderValidationError($e, $request);
        }

        $status = $e instanceof HttpException ? $e->status() : 500;
        $headers = $e instanceof HttpException ? $e->headers() : [];

        if ($status >= 500) {
            Logger::instance()->error($e->getMessage(), [
                'exception' => $e::class,
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'path'      => $request?->path,
                'trace'     => Config::isDebug() ? $e->getTraceAsString() : null,
            ]);
        }

        // Only messages attached to an HttpException are safe to show; any
        // other exception message may contain a path or a query.
        $message = $e instanceof HttpException && $e->getMessage() !== ''
            ? $e->getMessage()
            : $this->defaultMessageFor($status);

        if ($request !== null && $request->wantsJson()) {
            $payload = ['error' => $message, 'status' => $status];

            if (Config::isDebug() && $status >= 500) {
                $payload['exception'] = $e::class;
                $payload['at'] = $e->getFile() . ':' . $e->getLine();
                $payload['trace'] = explode("\n", $e->getTraceAsString());
            }

            return Response::json($payload, $status)->withHeaders($headers);
        }

        try {
            $html = View::render('errors/error', [
                'title'     => $status . ' — ' . $message,
                'status'    => $status,
                'message'   => $message,
                'exception' => Config::isDebug() ? $e : null,
            ]);
        } catch (\Throwable) {
            // The error page itself failed; fall back to something that cannot.
            $html = '<!doctype html><meta charset="utf-8"><title>' . e((string) $status) . '</title>'
                . '<h1>' . e((string) $status) . '</h1><p>' . e($message) . '</p>';
        }

        return Response::html($html, $status)->withHeaders($headers);
    }

    private function renderValidationError(ValidationException $e, ?Request $request): Response
    {
        if ($request !== null && $request->wantsJson()) {
            return Response::json([
                'error'  => $e->getMessage(),
                'errors' => $e->errors(),
                'status' => 422,
            ], 422);
        }

        Session::flash('_errors', $e->errors());
        Session::flash('_old', $e->input());
        Session::notify('negative', $e->first());

        $back = Session::get('_previous_url');

        return Response::redirect(is_string($back) && $back !== '' ? $back : path('/'), 303);
    }

    private function defaultMessageFor(int $status): string
    {
        return match ($status) {
            400     => __('error.bad_request'),
            401     => __('error.unauthorized'),
            403     => __('error.forbidden'),
            404     => __('error.not_found'),
            405     => __('error.method_not_allowed'),
            413     => __('error.payload_too_large'),
            419     => __('error.session_expired'),
            422     => __('error.unprocessable'),
            429     => __('error.too_many_requests'),
            503     => __('error.unavailable'),
            default => __('error.server_error'),
        };
    }

    /**
     * Runs after the response has been sent, where a slow step costs the
     * visitor nothing.
     */
    private function afterResponse(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // One request in roughly two hundred sweeps expired rate-limit buckets
        // and orphaned upload chunks. Cron is not available on every Strato
        // plan, so the work has to ride along with normal traffic.
        try {
            if (random_int(1, 200) === 1) {
                \MTL\Services\MaintenanceService::runOpportunisticTasks();
            }
        } catch (\Throwable $e) {
            Logger::instance()->warning('Background maintenance failed', ['error' => $e->getMessage()]);
        }
    }
}
