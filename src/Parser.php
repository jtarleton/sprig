<?php
declare(strict_types=1);

namespace JTarleton\Sprig;

/**
 * Parser
 * ------
 * Consumes a TokenStream and produces a Node tree.
 *
 * Recursive descent for statements; precedence-climbing for expressions.
 *
 * In real Twig each tag (if, for, set, block, extends, include, ...) has a
 * dedicated TokenParser class registered with the Environment, which lets
 * extensions add new tags. Here we hard-code the three tags the demo
 * needs as methods on the Parser itself.
 */
final class Parser
{
    /**
     * Operator precedence table (higher = tighter binding).
     * Mirrors Twig's standard precedences for the operators we support.
     */
    private const BINARY = [
        'or'  => 10,
        'and' => 15,
        '=='  => 20, '!=' => 20, '<' => 20, '>' => 20, '<=' => 20, '>=' => 20,
        '~'   => 25,
        '+'   => 30, '-' => 30,
        '*'   => 40, '/' => 40,
    ];

    private TokenStream $stream;

    public function parse(TokenStream $stream): ModuleNode
    {
        $this->stream = $stream;
        $body = $this->subparse();
        if (!$this->stream->isEOF()) {
            $t = $this->stream->current();
            throw new \RuntimeException("Unexpected '{$t->value}' on line {$t->line}");
        }
        return new ModuleNode($body);
    }

    /**
     * Parse a sequence of nodes. Stops *without consuming* the closing
     * `{% NAME %}` when NAME is in $until — caller handles those.
     *
     * @param string[] $until tag names that terminate this sub-parse
     */
    private function subparse(array $until = []): BodyNode
    {
        $nodes = [];
        while (!$this->stream->isEOF()) {
            $token = $this->stream->current();

            if ($token->type === Token::TEXT) {
                $nodes[] = new TextNode($token->value);
                $this->stream->next();
                continue;
            }

            if ($token->type === Token::VAR_START) {
                $this->stream->next();
                $expr = $this->parseExpression();
                $this->stream->expect(Token::VAR_END);
                $nodes[] = new PrintNode($expr);
                continue;
            }

            if ($token->type === Token::BLOCK_START) {
                // Peek the tag name. If it terminates this sub-parse,
                // hand control back without consuming anything.
                $tag = $this->stream->look(1);
                if ($tag->type === Token::NAME && in_array($tag->value, $until, true)) {
                    return new BodyNode($nodes);
                }
                $this->stream->next();                       // BLOCK_START
                $tagTok  = $this->stream->expect(Token::NAME);
                $nodes[] = match ($tagTok->value) {
                    'if'  => $this->parseIf(),
                    'for' => $this->parseFor(),
                    'set' => $this->parseSet(),
                    default => throw new \RuntimeException(
                        "Unknown tag '{$tagTok->value}' on line {$tagTok->line}"
                    ),
                };
                continue;
            }

            throw new \RuntimeException(
                "Unexpected token {$token->type} on line {$token->line}"
            );
        }

        return new BodyNode($nodes);
    }

    private function parseIf(): IfNode
    {
        $test = $this->parseExpression();
        $this->stream->expect(Token::BLOCK_END);
        $then = $this->subparse(['else', 'endif']);

        $else = null;
        if ($this->stream->look(1)->value === 'else') {
            $this->stream->expect(Token::BLOCK_START);
            $this->stream->expect(Token::NAME, 'else');
            $this->stream->expect(Token::BLOCK_END);
            $else = $this->subparse(['endif']);
        }

        $this->stream->expect(Token::BLOCK_START);
        $this->stream->expect(Token::NAME, 'endif');
        $this->stream->expect(Token::BLOCK_END);
        return new IfNode($test, $then, $else);
    }

    private function parseFor(): ForNode
    {
        $varTok = $this->stream->expect(Token::NAME);
        $this->stream->expect(Token::OPERATOR, 'in');
        $seq = $this->parseExpression();
        $this->stream->expect(Token::BLOCK_END);
        $body = $this->subparse(['endfor']);
        $this->stream->expect(Token::BLOCK_START);
        $this->stream->expect(Token::NAME, 'endfor');
        $this->stream->expect(Token::BLOCK_END);
        return new ForNode($varTok->value, $seq, $body);
    }

    private function parseSet(): SetNode
    {
        $varTok = $this->stream->expect(Token::NAME);
        $this->stream->expect(Token::OPERATOR, '=');
        $value = $this->parseExpression();
        $this->stream->expect(Token::BLOCK_END);
        return new SetNode($varTok->value, $value);
    }

    // -----------------------------------------------------------------------
    // Expressions: precedence-climbing parser
    // -----------------------------------------------------------------------

    private function parseExpression(int $minPrecedence = 0): Node
    {
        $left = $this->parseUnary();

        while (true) {
            $tok = $this->stream->current();
            if ($tok->type !== Token::OPERATOR || !isset(self::BINARY[$tok->value])) {
                break;
            }
            $prec = self::BINARY[$tok->value];
            if ($prec < $minPrecedence) {
                break;
            }
            $this->stream->next();
            // Left-associative: right side must bind strictly tighter.
            $right = $this->parseExpression($prec + 1);
            $left  = new BinaryExpression($tok->value, $left, $right);
        }
        return $left;
    }

    private function parseUnary(): Node
    {
        $tok = $this->stream->current();
        if ($tok->type === Token::OPERATOR && ($tok->value === 'not' || $tok->value === '-')) {
            $this->stream->next();
            return new UnaryExpression($tok->value, $this->parseUnary());
        }
        return $this->parsePostfix($this->parsePrimary());
    }

    private function parsePrimary(): Node
    {
        $tok = $this->stream->current();

        if ($tok->type === Token::NUMBER || $tok->type === Token::STRING) {
            $this->stream->next();
            return new ConstantExpression($tok->value);
        }

        if ($tok->type === Token::NAME) {
            $this->stream->next();
            return match ($tok->value) {
                'true'  => new ConstantExpression(true),
                'false' => new ConstantExpression(false),
                'null'  => new ConstantExpression(null),
                default => new NameExpression($tok->value),
            };
        }

        if ($tok->type === Token::PUNCTUATION && $tok->value === '(') {
            $this->stream->next();
            $expr = $this->parseExpression();
            $this->stream->expect(Token::PUNCTUATION, ')');
            return $expr;
        }

        throw new \RuntimeException(
            "Unexpected token {$tok->type}(" . var_export($tok->value, true) . ") on line {$tok->line}"
        );
    }

    /** Handle `.attr`, `[expr]`, and `|filter` chains, in any order. */
    private function parsePostfix(Node $node): Node
    {
        while (true) {
            $tok = $this->stream->current();

            if ($tok->type === Token::PUNCTUATION && $tok->value === '.') {
                $this->stream->next();
                $attrTok = $this->stream->expect(Token::NAME);
                $node = new AttributeExpression(
                    $node,
                    new ConstantExpression($attrTok->value)
                );
                continue;
            }

            if ($tok->type === Token::PUNCTUATION && $tok->value === '[') {
                $this->stream->next();
                $attr = $this->parseExpression();
                $this->stream->expect(Token::PUNCTUATION, ']');
                $node = new AttributeExpression($node, $attr);
                continue;
            }

            if ($tok->type === Token::PUNCTUATION && $tok->value === '|') {
                $this->stream->next();
                $name = $this->stream->expect(Token::NAME)->value;
                $args = [];
                if ($this->stream->test(Token::PUNCTUATION, '(')) {
                    $this->stream->next();
                    if (!$this->stream->test(Token::PUNCTUATION, ')')) {
                        $args[] = $this->parseExpression();
                        while ($this->stream->test(Token::PUNCTUATION, ',')) {
                            $this->stream->next();
                            $args[] = $this->parseExpression();
                        }
                    }
                    $this->stream->expect(Token::PUNCTUATION, ')');
                }
                $node = new FilterExpression($node, $name, $args);
                continue;
            }

            break;
        }
        return $node;
    }
}
