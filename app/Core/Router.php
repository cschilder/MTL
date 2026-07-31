<?php

declare(strict_types=1);

namespace MTL\Core;

use MTL\Http\Middleware\MiddlewareInterface;

defined('MTL_APP') || exit;

/**
 * Route table and dispatcher.
 *
 * Patterns use {name} for a single path segment and {name:regex} to constrain
 * it, e.g. /trips/{trip}/steps/{step:\d+}. A trailing {path:.*} captures the
 * remainder, which the media controller uses for nested storage keys.
 */
final class Router
{
    /** @var array<string,list<array{pattern:string,regex:string,handler:mixed,middleware:list<string>,name:?string}>> */
    private array $routes = [];

    /** @var array<string,string> route name => pattern */
    private array $names = [];

    /** @var array{prefix:string,middleware:list<string>} */
    private array $group = ['prefix' => '', 'middleware' => []];

    /** @var array<string,class-string<MiddlewareInterface>> */
    private array $middlewareAliases = [];

    /** @var list<string> middleware applied to every route */
    private array $globalMiddleware = [];

    private static ?self $instance = null;

    public function __construct()
    {
        self::$instance = $this;
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * @param array<string,class-string<MiddlewareInterface>> $aliases
     */
    public function aliasMiddleware(array $aliases): self
    {
        $this->middlewareAliases += $aliases;

        return $this;
    }

    /**
     * @param list<string> $middleware
     */
    public function globalMiddleware(array $middleware): self
    {
        $this->globalMiddleware = $middleware;

        return $this;
    }

    public function get(string $pattern, mixed $handler): self
    {
        // A GET route always answers HEAD too; the dispatcher discards the
        // body so caches and link checkers behave.
        return $this->add('GET', $pattern, $handler)->add('HEAD', $pattern, $handler);
    }

    public function post(string $pattern, mixed $handler): self
    {
        return $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, mixed $handler): self
    {
        return $this->add('PUT', $pattern, $handler);
    }

    public function patch(string $pattern, mixed $handler): self
    {
        return $this->add('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, mixed $handler): self
    {
        return $this->add('DELETE', $pattern, $handler);
    }

    /**
     * @param list<string> $methods
     */
    public function match(array $methods, string $pattern, mixed $handler): self
    {
        foreach ($methods as $method) {
            $this->add(strtoupper($method), $pattern, $handler);
        }

        return $this;
    }

    private function add(string $method, string $pattern, mixed $handler): self
    {
        $pattern = $this->group['prefix'] . '/' . trim($pattern, '/');
        $pattern = '/' . trim($pattern, '/');
        $pattern = $pattern === '/' ? '/' : rtrim($pattern, '/');

        $this->routes[$method][] = [
            'pattern'    => $pattern,
            'regex'      => $this->compile($pattern),
            'handler'    => $handler,
            'middleware' => $this->group['middleware'],
            'name'       => null,
        ];

        return $this;
    }

    /** Names the most recently registered route so route() can build its URL. */
    public function name(string $name): self
    {
        foreach ($this->routes as $method => $routes) {
            $last = array_key_last($routes);
            if ($last === null) {
                continue;
            }
            // HEAD mirrors GET, so naming the GET route is enough; without
            // this guard the name would resolve to the HEAD duplicate.
            if ($method === 'HEAD') {
                continue;
            }
            if ($this->routes[$method][$last]['name'] === null) {
                $this->routes[$method][$last]['name'] = $name;
                $this->names[$name] = $this->routes[$method][$last]['pattern'];
                break;
            }
        }

        return $this;
    }

    /**
     * @param array{prefix?:string,middleware?:list<string>} $attributes
     */
    public function group(array $attributes, callable $definition): self
    {
        $previous = $this->group;

        $this->group = [
            'prefix'     => $previous['prefix'] . '/' . trim($attributes['prefix'] ?? '', '/'),
            'middleware' => array_merge($previous['middleware'], $attributes['middleware'] ?? []),
        ];
        $this->group['prefix'] = rtrim($this->group['prefix'], '/');

        $definition($this);

        $this->group = $previous;

        return $this;
    }

    /**
     * Turns /trips/{slug} into a regex with named capture groups.
     */
    private function compile(string $pattern): string
    {
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^{}]+(?:\{[0-9,]+\})?))?\}/',
            static function (array $m): string {
                $name = $m[1];
                $constraint = $m[2] ?? '[^/]+';

                return '(?P<' . $name . '>' . $constraint . ')';
            },
            $pattern
        );

        return '#^' . $regex . '$#u';
    }

    /**
     * Builds the URL for a named route.
     *
     * @param array<string,scalar> $params
     */
    public function urlFor(string $name, array $params = []): string
    {
        if (!isset($this->names[$name])) {
            throw new \InvalidArgumentException('Unknown route name: ' . $name);
        }

        $pattern = $this->names[$name];
        $used = [];

        $url = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^{}]+)?\}/',
            static function (array $m) use ($params, &$used): string {
                $key = $m[1];
                if (!array_key_exists($key, $params)) {
                    throw new \InvalidArgumentException('Missing route parameter "' . $key . '"');
                }
                $used[$key] = true;

                return rawurlencode((string) $params[$key]);
            },
            $pattern
        ) ?? $pattern;

        // Leftover parameters become the query string, which is what makes
        // route('trips.index', ['page' => 2]) work.
        $query = array_diff_key($params, $used);

        return path($url, $query);
    }

    public function hasName(string $name): bool
    {
        return isset($this->names[$name]);
    }

    /**
     * Finds the matching route and runs it through its middleware stack.
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->method;
        $candidates = $this->routes[$method] ?? [];

        foreach ($candidates as $route) {
            if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }

            $request->withRouteParams($params);

            $middleware = array_merge($this->globalMiddleware, $route['middleware']);

            return $this->runPipeline($request, $middleware, fn (Request $r) => $this->invoke($route['handler'], $r));
        }

        // Distinguish "wrong verb" from "no such page" so a stale form POST
        // reports 405 instead of a confusing 404.
        foreach ($this->routes as $otherMethod => $routes) {
            if ($otherMethod === $method) {
                continue;
            }
            foreach ($routes as $route) {
                if (preg_match($route['regex'], $request->path) === 1) {
                    throw new HttpException(405, 'Method ' . $method . ' is not allowed for this URL.');
                }
            }
        }

        throw new HttpException(404, 'Page not found.');
    }

    /**
     * @param list<string> $middleware
     */
    private function runPipeline(Request $request, array $middleware, callable $destination): Response
    {
        $next = $destination;

        // Build the chain back to front so the first entry runs outermost.
        foreach (array_reverse($middleware) as $entry) {
            $current = $next;
            $next = function (Request $r) use ($entry, $current): Response {
                [$name, $argument] = array_pad(explode(':', $entry, 2), 2, null);

                $class = $this->middlewareAliases[$name] ?? $name;
                if (!class_exists($class)) {
                    throw new \RuntimeException('Unknown middleware: ' . $entry);
                }

                /** @var MiddlewareInterface $instance */
                $instance = new $class();

                return $instance->handle($r, $current, $argument);
            };
        }

        return $next($request);
    }

    private function invoke(mixed $handler, Request $request): Response
    {
        if (is_callable($handler)) {
            $result = $handler($request);
        } elseif (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $controller = is_object($class) ? $class : new $class();
            $result = $controller->{$method}($request);
        } else {
            throw new \RuntimeException('Route handler is not callable.');
        }

        if ($result instanceof Response) {
            return $result;
        }

        if (is_string($result)) {
            return Response::html($result);
        }

        if ($result === null) {
            return Response::noContent();
        }

        return Response::json($result);
    }
}
