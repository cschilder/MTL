<?php

declare(strict_types=1);

namespace MTL\Core;

defined('MTL_APP') || exit;

/**
 * Plain-PHP template renderer with layouts, sections and partials.
 *
 * Templates live in app/Views and are written in PHP with short echo tags, so
 * there is no compilation step to go wrong on a host where the cache directory
 * happens to be read-only.
 *
 * Inside a template, $this is the View instance:
 *
 *     <?php $this->layout('layouts/app', ['title' => $trip['title']]) ?>
 *     <?php $this->start('content') ?>
 *         ...
 *     <?php $this->end() ?>
 */
final class View
{
    /** @var array<string,mixed> data shared with every template */
    private static array $shared = [];

    /** @var array<string,string> rendered section name => HTML */
    private array $sections = [];

    /** @var list<string> stack of section names currently being captured */
    private array $capturing = [];

    private ?string $layoutTemplate = null;

    /** @var array<string,mixed> */
    private array $layoutData = [];

    /** @var array<string,mixed> */
    private array $data;

    private function __construct(private readonly string $template, array $data)
    {
        $this->data = $data;
    }

    /**
     * Adds data available to every template rendered afterwards.
     *
     * @param array<string,mixed> $data
     */
    public static function share(array $data): void
    {
        self::$shared = array_merge(self::$shared, $data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function render(string $template, array $data = []): string
    {
        return (new self($template, $data))->evaluate();
    }

    public static function exists(string $template): bool
    {
        return is_file(self::resolve($template));
    }

    private static function resolve(string $template): string
    {
        // Templates are named with slashes and never carry an extension, so a
        // traversal attempt cannot escape the views directory.
        $clean = str_replace(['..', '\\'], '', $template);
        $clean = trim($clean, '/');

        return MTL_ROOT . '/app/Views/' . $clean . '.php';
    }

    private function evaluate(): string
    {
        $file = self::resolve($this->template);

        if (!is_file($file)) {
            throw new \RuntimeException('View not found: ' . $this->template);
        }

        $level = ob_get_level();
        ob_start();

        try {
            // Template variables are extracted into local scope; shared data
            // comes first so a caller can always override it.
            extract(array_merge(self::$shared, $this->data), EXTR_SKIP);
            require $file;
        } catch (\Throwable $e) {
            // Discard partial output so an exception never leaks half a page.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $e;
        }

        $content = (string) ob_get_clean();

        if ($this->capturing !== []) {
            throw new \RuntimeException(
                'Unclosed section "' . end($this->capturing) . '" in view ' . $this->template
            );
        }

        if ($this->layoutTemplate === null) {
            return $content;
        }

        // Anything a template echoed outside a section becomes the default
        // content section, which keeps simple pages free of boilerplate.
        if (!isset($this->sections['content']) && trim($content) !== '') {
            $this->sections['content'] = $content;
        }

        $layout = new self($this->layoutTemplate, array_merge($this->data, $this->layoutData));
        $layout->sections = $this->sections;

        return $layout->evaluate();
    }

    // -------------------------------------------------------------------------
    // Template API
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $data
     */
    public function layout(string $template, array $data = []): void
    {
        $this->layoutTemplate = $template;
        $this->layoutData = $data;
    }

    public function start(string $section): void
    {
        $this->capturing[] = $section;
        ob_start();
    }

    public function end(): void
    {
        if ($this->capturing === []) {
            throw new \LogicException('View::end() called without a matching start().');
        }

        $section = array_pop($this->capturing);
        $this->sections[$section] = (string) ob_get_clean();
    }

    /** Appends to a section instead of replacing it. */
    public function append(string $section): void
    {
        $this->capturing[] = $section;
        $this->sections[$section] ??= '';
        ob_start();
    }

    public function endAppend(): void
    {
        $section = array_pop($this->capturing);
        if ($section === null) {
            throw new \LogicException('View::endAppend() called without a matching append().');
        }

        $this->sections[$section] = ($this->sections[$section] ?? '') . (string) ob_get_clean();
    }

    /** Outputs a captured section. Content is already-escaped HTML. */
    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    public function hasSection(string $name): bool
    {
        return isset($this->sections[$name]) && trim($this->sections[$name]) !== '';
    }

    /**
     * Renders another template inline, inheriting the current data.
     *
     * @param array<string,mixed> $data
     */
    public function include(string $template, array $data = []): string
    {
        return self::render($template, array_merge($this->data, $data));
    }

    /**
     * Renders a template once per item, for lists of cards or table rows.
     *
     * @param iterable<array-key,mixed> $items
     */
    public function each(string $template, iterable $items, string $as = 'item', string $empty = ''): string
    {
        $out = '';
        $count = 0;

        foreach ($items as $key => $item) {
            $out .= self::render($template, array_merge($this->data, [
                $as       => $item,
                'loopKey' => $key,
                'loopIndex' => $count,
            ]));
            ++$count;
        }

        if ($count === 0 && $empty !== '') {
            return self::render($empty, $this->data);
        }

        return $out;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data) || array_key_exists($key, self::$shared);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? self::$shared[$key] ?? $default;
    }
}
