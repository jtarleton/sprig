<?php
declare(strict_types=1);

// Tiny dumb autoloader. In a real project you'd use Composer's PSR-4.
// Order matters only because some files have multiple classes and we
// load by file rather than by class name.
require_once __DIR__ . '/Lexer.php';
require_once __DIR__ . '/Node.php';
require_once __DIR__ . '/Parser.php';
require_once __DIR__ . '/Compiler.php';
require_once __DIR__ . '/Template.php';
require_once __DIR__ . '/Loader.php';
require_once __DIR__ . '/Environment.php';
