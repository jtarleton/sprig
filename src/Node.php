<?php
declare(strict_types=1);

namespace JTarleton\Sprig;

/**
 * Nodes form the abstract syntax tree (AST) the Parser produces.
 * Each node knows how to compile *itself* to PHP via the Compiler.
 *
 * In real Twig the node hierarchy is much richer — separate classes for
 * each tag, expression type, etc., split across many files. Here we keep
 * them all in one file so the whole tree is easy to scan.
 *
 *   Statement nodes  (emit a full PHP statement, write their own indent)
 *     ModuleNode, BodyNode, TextNode, PrintNode, IfNode, ForNode, SetNode
 *
 *   Expression nodes (emit an inline PHP fragment)
 *     NameExpression, ConstantExpression, BinaryExpression,
 *     UnaryExpression, AttributeExpression, FilterExpression
 */
abstract class Node
{
    abstract public function compile(Compiler $c): void;
}

// ---------------------------------------------------------------------------
// Statement nodes
// ---------------------------------------------------------------------------

/** Root of every template. */
final class ModuleNode extends Node
{
    public function __construct(public readonly Node $body) {}

    public function compile(Compiler $c): void
    {
        $this->body->compile($c);
    }
}

/** A sequence of nodes (template body, if-branch, for-body, ...). */
final class BodyNode extends Node
{
    /** @param Node[] $nodes */
    public function __construct(public readonly array $nodes) {}

    public function compile(Compiler $c): void
    {
        foreach ($this->nodes as $node) {
            $node->compile($c);
        }
    }
}

/** Static text outside `{{ }}` / `{% %}`. */
final class TextNode extends Node
{
    public function __construct(public readonly string $text) {}

    public function compile(Compiler $c): void
    {
        if ($this->text === '') {
            return;
        }
        $c->writeIndent()->raw('echo ')->repr($this->text)->raw(";\n");
    }
}

/** `{{ expression }}` */
final class PrintNode extends Node
{
    public function __construct(public readonly Node $expr) {}

    public function compile(Compiler $c): void
    {
        $c->writeIndent()->raw('echo (string)(');
        $this->expr->compile($c);
        $c->raw(");\n");
    }
}

/** `{% if test %}...{% else %}...{% endif %}` */
final class IfNode extends Node
{
    public function __construct(
        public readonly Node $test,
        public readonly Node $then,
        public readonly ?Node $else = null,
    ) {}

    public function compile(Compiler $c): void
    {
        $c->writeIndent()->raw('if (');
        $this->test->compile($c);
        $c->raw(") {\n")->indent();
        $this->then->compile($c);
        $c->outdent()->writeIndent()->raw('}');

        if ($this->else !== null) {
            $c->raw(" else {\n")->indent();
            $this->else->compile($c);
            $c->outdent()->writeIndent()->raw('}');
        }
        $c->raw("\n");
    }
}

/** `{% for var in seq %}...{% endfor %}` */
final class ForNode extends Node
{
    public function __construct(
        public readonly string $var,
        public readonly Node $seq,
        public readonly Node $body,
    ) {}

    public function compile(Compiler $c): void
    {
        // Real Twig saves and restores the loop variable in a separate
        // scope and exposes a `loop` helper; we just clobber $context for
        // simplicity. (Easy upgrade if you want it.)
        $c->writeIndent()->raw('foreach ((');
        $this->seq->compile($c);
        $c->raw(' ?? []) as $context[')->repr($this->var)->raw("]) {\n")->indent();
        $this->body->compile($c);
        $c->outdent()->writeIndent()->raw("}\n");
    }
}

/** `{% set name = expr %}` */
final class SetNode extends Node
{
    public function __construct(
        public readonly string $var,
        public readonly Node $value,
    ) {}

    public function compile(Compiler $c): void
    {
        $c->writeIndent()->raw('$context[')->repr($this->var)->raw('] = ');
        $this->value->compile($c);
        $c->raw(";\n");
    }
}

// ---------------------------------------------------------------------------
// Expression nodes — these emit inline PHP, never a full statement.
// ---------------------------------------------------------------------------

/** A bare identifier, e.g. `name` -> `$context['name']`. */
final class NameExpression extends Node
{
    public function __construct(public readonly string $name) {}

    public function compile(Compiler $c): void
    {
        $c->raw('($context[')->repr($this->name)->raw('] ?? null)');
    }
}

/** A literal: number, string, true, false, null. */
final class ConstantExpression extends Node
{
    public function __construct(public readonly mixed $value) {}

    public function compile(Compiler $c): void
    {
        $c->repr($this->value);
    }
}

final class BinaryExpression extends Node
{
    public function __construct(
        public readonly string $op,
        public readonly Node $left,
        public readonly Node $right,
    ) {}

    public function compile(Compiler $c): void
    {
        // Map Twig operators to PHP equivalents.
        $php = match ($this->op) {
            'and' => '&&',
            'or'  => '||',
            '~'   => '.',   // Twig string concatenation
            default => $this->op,
        };
        $c->raw('(');
        $this->left->compile($c);
        $c->raw(" $php ");
        $this->right->compile($c);
        $c->raw(')');
    }
}

final class UnaryExpression extends Node
{
    public function __construct(
        public readonly string $op,
        public readonly Node $expr,
    ) {}

    public function compile(Compiler $c): void
    {
        $php = $this->op === 'not' ? '!' : $this->op;
        $c->raw("($php");
        $this->expr->compile($c);
        $c->raw(')');
    }
}

/** `foo.bar` or `foo[bar]`  →  Template::getAttribute(foo, 'bar') */
final class AttributeExpression extends Node
{
    public function __construct(
        public readonly Node $object,
        public readonly Node $attribute,
    ) {}

    public function compile(Compiler $c): void
    {
        $c->raw('\\Sprig\\Template::getAttribute(');
        $this->object->compile($c);
        $c->raw(', ');
        $this->attribute->compile($c);
        $c->raw(')');
    }
}

/** `expr|filter` or `expr|filter(arg1, arg2)` */
final class FilterExpression extends Node
{
    /** @param Node[] $args */
    public function __construct(
        public readonly Node $expr,
        public readonly string $filter,
        public readonly array $args = [],
    ) {}

    public function compile(Compiler $c): void
    {
        $c->raw('$this->env->callFilter(')->repr($this->filter)->raw(', ');
        $this->expr->compile($c);
        foreach ($this->args as $arg) {
            $c->raw(', ');
            $arg->compile($c);
        }
        $c->raw(')');
    }
}
