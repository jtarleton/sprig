<?php
declare(strict_types=1);

namespace Sprig;

/**
 * A Loader knows how to fetch the source text for a template name.
 *
 * Decoupling "where templates live" from "how they're parsed" is what
 * lets real Twig load from disk, a database, an array, a chain of
 * sources, etc. The same architecture, just two implementations here.
 */
interface Loader
{
    public function getSource(string $name): string;
}

/** Reads templates from a directory on disk. */
final class FilesystemLoader implements Loader
{
    public function __construct(private readonly string $directory) {}

    public function getSource(string $name): string
    {
        $path = rtrim($this->directory, '/') . '/' . $name;
        if (!is_file($path)) {
            throw new \RuntimeException("Template not found: {$name}");
        }
        return file_get_contents($path);
    }
}

/** Reads templates from an in-memory map. Handy for examples and tests. */
final class ArrayLoader implements Loader
{
    /** @param array<string,string> $templates */
    public function __construct(private readonly array $templates) {}

    public function getSource(string $name): string
    {
        if (!isset($this->templates[$name])) {
            throw new \RuntimeException("Template not found: {$name}");
        }
        return $this->templates[$name];
    }
}
