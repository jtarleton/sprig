<?php
declare(strict_types=1);

namespace Sprig;

/**
 * Environment
 * -----------
 * The user-facing entry point. Wires the pipeline together:
 *
 *     source  →  Lexer    →  TokenStream
 *     tokens  →  Parser   →  Node tree
 *     nodes   →  Compiler →  PHP source
 *     PHP     →  eval()   →  Template instance
 *     render  →  doDisplay() output
 *
 * Real Twig writes the compiled PHP to a cache directory keyed by a
 * hash of the source, then `require`s it. We just eval() — same idea,
 * minus the disk write and the cache invalidation logic.
 *
 * The Environment also owns the filter registry. callFilter() is what
 * compiled templates invoke for `{{ x|upper }}` etc.
 */
final class Environment
{
    /** @var array<string, callable> */
    private array $filters = [];

    /** @var array<string, Template> Loaded template cache (per process). */
    private array $templates = [];

    /** Counter for generating unique class names. */
    private static int $classCounter = 0;

    public function __construct(private readonly Loader $loader)
    {
        $this->registerDefaults();
    }

    public function addFilter(string $name, callable $fn): void
    {
        $this->filters[$name] = $fn;
    }

    public function callFilter(string $name, mixed ...$args): mixed
    {
        if (!isset($this->filters[$name])) {
            throw new \RuntimeException("Unknown filter: {$name}");
        }
        return ($this->filters[$name])(...$args);
    }

    /** Render a template by name with the given variables. */
    public function render(string $name, array $context = []): string
    {
        return $this->load($name)->render($context);
    }

    /**
     * Compile a template to PHP source — useful for showing students
     * what Twig actually generates. Not used by render().
     */
    public function compile(string $name): string
    {
        $source = $this->loader->getSource($name);
        return (new Compiler())->compile(
            (new Parser())->parse((new Lexer())->tokenize($source)),
            '__Sprig_Inspect_' . preg_replace('/[^A-Za-z0-9_]/', '_', $name),
        );
    }

    /** Load (and compile, on first use) a template. */
    private function load(string $name): Template
    {
        if (isset($this->templates[$name])) {
            return $this->templates[$name];
        }

        $source = $this->loader->getSource($name);

        // Each compiled template needs a unique class name, since we may
        // load multiple templates and PHP can't redeclare classes.
        $className = '__SprigTemplate_' . ++self::$classCounter
                   . '_' . preg_replace('/[^A-Za-z0-9_]/', '_', $name);

        $php = (new Compiler())->compile(
            (new Parser())->parse((new Lexer())->tokenize($source)),
            $className,
        );

        // Strip the leading `<?php` so we can eval() the rest.
        $php = preg_replace('/^<\?php\s*/', '', $php, 1);
        eval($php);

        return $this->templates[$name] = new $className($this);
    }

    private function registerDefaults(): void
    {
        $this->filters['upper']  = static fn(mixed $v): string => strtoupper((string) $v);
        $this->filters['lower']  = static fn(mixed $v): string => strtolower((string) $v);
        $this->filters['length'] = static fn(mixed $v): int    =>
            is_countable($v) ? count($v) : strlen((string) $v);
        $this->filters['default'] = static fn(mixed $v, mixed $d = '') =>
            ($v === null || $v === '') ? $d : $v;
        $this->filters['escape'] = $this->filters['e'] = static fn(mixed $v): string =>
            htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $this->filters['join']   = static fn(mixed $v, string $glue = '') =>
            implode($glue, is_array($v) ? $v : []);
    }
}
