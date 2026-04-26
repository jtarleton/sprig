<?php
declare(strict_types=1);

namespace Sprig;

/**
 * Template
 * --------
 * Base class that every compiled template extends.
 *
 * The Compiler emits a subclass with a single doDisplay() method that
 * `echo`s output. render() captures that output via output buffering
 * and returns it as a string.
 *
 * In real Twig, compiled templates also extend a Template base class —
 * the same architecture, just with many more hooks (blocks, parents,
 * traits, macros, sandboxing, ...).
 */
abstract class Template
{
    public function __construct(protected Environment $env) {}

    /** Render the template with the given variables and return the output. */
    public function render(array $context = []): string
    {
        ob_start();
        try {
            $this->doDisplay($context);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return ob_get_clean();
    }

    /** Implemented by every compiled subclass. */
    abstract protected function doDisplay(array $context): void;

    /**
     * Resolve `obj.attr` / `obj[attr]` at runtime.
     *
     * Twig's `.` is intentionally polymorphic: array key, public property,
     * or getter/isser/method — whichever exists. This is the runtime
     * helper that makes that work; AttributeExpression compiles to a
     * call to this method.
     */
    public static function getAttribute(mixed $object, mixed $attr): mixed
    {
        if ($object === null) {
            return null;
        }

        // Arrays and ArrayAccess: treat $attr as a key.
        if (is_array($object) || $object instanceof \ArrayAccess) {
            return $object[$attr] ?? null;
        }

        if (is_object($object)) {
            $name = (string) $attr;

            // Public property?
            if (isset($object->$name)) {
                return $object->$name;
            }

            // Getter / isser / bare method?
            foreach (['get' . ucfirst($name), 'is' . ucfirst($name), $name] as $method) {
                if (method_exists($object, $method)) {
                    return $object->$method();
                }
            }
        }

        return null;
    }
}
