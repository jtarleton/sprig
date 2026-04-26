<?php
declare(strict_types=1);

$root     = dirname(__DIR__);
$composer = $root . '/vendor/autoload.php';
file_exists($composer) ? require $composer : require $root . '/src/autoload.php';

use JTarleton\Sprig\ArrayLoader;
use JTarleton\Sprig\Environment;

// ---------------------------------------------------------------------------
// Templates
// ---------------------------------------------------------------------------

$templates = [
    'hello' =>
        "Hello, {{ name|upper }}!\n",

    'logic' =>
        "{# a comment — won't appear in output #}" .
        "{% if user.admin %}" .
            "Welcome back, admin {{ user.name }}.\n" .
        "{% else %}" .
            "Hi {{ user.name|default('stranger') }}.\n" .
        "{% endif %}",

    'cart' =>
        "Cart for {{ customer }}:\n" .
        "{% for item in items %}" .
            "  - {{ item.name }} x{{ item.qty }} = \${{ item.price * item.qty }}\n" .
        "{% endfor %}" .
        "Total items: {{ items|length }}\n",

    'set_demo' =>
        "{% set greeting = 'Hello, ' ~ name %}" .
        "{{ greeting }} (length: {{ greeting|length }})\n",

    'escape' =>
        "Safe: {{ html|e }}\nRaw:  {{ html }}\n",
];

$env = new Environment(new ArrayLoader($templates));

// A small helper class to show that `obj.attr` works on real objects too,
// not just arrays. AttributeExpression resolves both the same way.
$user = new class {
    public string $name = 'Alice';
    public function isAdmin(): bool { return true; }
};

// ---------------------------------------------------------------------------
// Render each template
// ---------------------------------------------------------------------------

$cases = [
    ['hello',    ['name' => 'James']],
    ['logic',    ['user' => $user]],
    ['logic',    ['user' => ['name' => '', 'admin' => false]]],
    ['cart',     [
        'customer' => 'James',
        'items'    => [
            ['name' => 'Coffee', 'qty' => 2, 'price' => 4],
            ['name' => 'Bagel',  'qty' => 1, 'price' => 3],
        ],
    ]],
    ['set_demo', ['name' => 'world']],
    ['escape',   ['html' => '<script>alert("xss")</script>']],
];

foreach ($cases as [$name, $context]) {
    echo "─── render('{$name}') " . str_repeat('─', 50 - strlen($name)) . "\n";
    echo $env->render($name, $context);
    echo "\n";
}

// ---------------------------------------------------------------------------
// Show what Twig actually compiles a template down to.
// This is the key insight: Twig templates become regular PHP classes.
// ---------------------------------------------------------------------------

echo "─── Compiled PHP for 'cart' " . str_repeat('─', 39) . "\n";
echo $env->compile('cart');
