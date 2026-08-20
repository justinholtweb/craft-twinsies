<?php
/**
 * Report methods on Twinsies' services that collide with `craft\base\Component`.
 *
 * A collision is not a warning here — an incompatible override is a **compile** error that fires
 * the moment the class is autoloaded, nowhere near the call site. Worth being able to re-run.
 *
 *     ddev exec php /var/www/craft-twinsies/tests/tools/collisions.php
 */

$root = getcwd();
require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

$baseMethods = [];

foreach ((new ReflectionClass(craft\base\Component::class))->getMethods() as $method) {
    $baseMethods[strtolower($method->getName())] = $method;
}

$found = 0;

foreach (glob(dirname(__DIR__, 2) . '/src/services/*.php') as $file) {
    $class = 'justinholtweb\\twinsies\\services\\' . basename($file, '.php');

    if (!class_exists($class) || !is_subclass_of($class, craft\base\Component::class)) {
        continue;
    }

    preg_match_all('/^\s*(?:public|private|protected)\s+(?:static\s+)?function\s+(\w+)/m', file_get_contents($file), $matches);

    foreach ($matches[1] as $name) {
        $key = strtolower($name);

        if (!isset($baseMethods[$key])) {
            continue;
        }

        $base = $baseMethods[$key];
        $found++;
        echo "COLLISION  {$class}::{$name}()  vs  {$base->getDeclaringClass()->getName()}::{$name}()"
            . ($base->isStatic() ? ' [static]' : '') . "\n";
    }
}

echo $found === 0 ? "No collisions.\n" : "\n{$found} collision(s).\n";
exit($found > 0 ? 1 : 0);
