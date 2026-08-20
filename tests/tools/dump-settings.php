<?php
/**
 * Print Twinsies' settings as project config actually stored them.
 *
 *     ddev exec php /var/www/craft-twinsies/tests/tools/dump-settings.php
 */

$root = getcwd();
require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

// `getStoredPluginInfo()` is memoised for the life of the process, so settings saved by another
// request have to be read straight out of project config.
$settings = Craft::$app->getProjectConfig()->get('plugins.twinsies.settings') ?? [];

foreach ($settings as $key => $value) {
    echo str_pad($key, 26), json_encode($value), "\n";
}

echo "\n", count($settings), " settings stored.\n";
