<?php
/**
 * Check the translation files against the strings the source actually uses.
 *
 *     ddev exec php /var/www/craft-twinsies/tests/tools/translations.php
 *
 * Catches the three ways a translation file rots: a key that no longer exists in the source, a
 * source string with no translation, and a translation that dropped or mangled a `{placeholder}`,
 * a `**bold**` run, a `` `code` `` span or a `[link]({url})` — any of which renders as literal
 * rubbish in front of a customer.
 */

$root = dirname(__DIR__, 2);
$problems = 0;

/** @return string[] */
function sourceStrings(string $root): array
{
    $found = [];

    $walk = static function(string $dir) use (&$walk, &$found) {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = "{$dir}/{$entry}";

            if (is_dir($path)) {
                $walk($path);
                continue;
            }

            $contents = @file_get_contents($path);

            if ($contents === false) {
                continue;
            }

            if (str_ends_with($entry, '.php')) {
                preg_match_all("/Craft::t\(\s*'twinsies'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'/", $contents, $m);
                $found = array_merge($found, $m[1]);
            } elseif (str_ends_with($entry, '.twig')) {
                preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'\|t\('twinsies'/", $contents, $m);
                $found = array_merge($found, $m[1]);
            }
        }
    };

    $walk($root . '/src');

    $unescaped = array_map(
        static fn(string $s) => str_replace(["\\'", '\\\\'], ["'", '\\'], $s),
        $found,
    );

    $unique = array_unique($unescaped);
    sort($unique);

    return $unique;
}

/**
 * The bits of a string that must survive translation unchanged.
 *
 * Placeholders and code spans are literal — `1300` is a ledger account and `{url}` is substituted
 * by Craft, so translating either breaks the string. Emphasis is different: the *words* inside
 * `**…**` are meant to be translated, and only the number of emphasised runs has to match, or the
 * Markdown renders as literal asterisks.
 *
 * @return array{literal: string[], emphasis: int}
 */
function tokens(string $value): array
{
    preg_match_all('/\{[a-zA-Z0-9_]+\}|`[^`]+`/', $value, $literal);
    preg_match_all('/\*\*[^*]+\*\*/', $value, $emphasis);

    $tokens = $literal[0];
    sort($tokens);

    return ['literal' => $tokens, 'emphasis' => count($emphasis[0])];
}

$source = sourceStrings($root);
echo count($source), " translatable strings in the source.\n";

foreach (glob($root . '/src/translations/*', GLOB_ONLYDIR) as $dir) {
    $locale = basename($dir);
    $file = "{$dir}/twinsies.php";

    if (!is_file($file)) {
        continue;
    }

    /** @var array<string, string> $messages */
    $messages = require $file;

    echo "\n{$locale} — ", count($messages), " entries\n";

    foreach ($source as $string) {
        if (!array_key_exists($string, $messages)) {
            echo "  MISSING     ", json_encode($string), "\n";
            $problems++;
            continue;
        }

        $translated = $messages[$string];

        if (trim($translated) === '') {
            echo "  EMPTY       ", json_encode($string), "\n";
            $problems++;
            continue;
        }

        $want = tokens($string);
        $got = tokens($translated);

        if ($want['literal'] !== $got['literal']) {
            echo "  PLACEHOLDERS ", json_encode($string), "\n";
            echo "               expected ", json_encode($want['literal']), "\n";
            echo "               got      ", json_encode($got['literal']), "\n";
            $problems++;
        }

        if ($want['emphasis'] !== $got['emphasis']) {
            echo "  EMPHASIS    ", json_encode($string), "\n";
            echo "              expected ", $want['emphasis'], " bold run(s), got ", $got['emphasis'], "\n";
            $problems++;
        }
    }

    foreach (array_keys($messages) as $key) {
        if (!in_array($key, $source, true)) {
            echo "  ORPHANED    ", json_encode($key), "\n";
            $problems++;
        }
    }

    // A locale where nothing was actually translated is a file nobody finished.
    if ($locale !== 'en') {
        $identical = 0;

        foreach ($messages as $key => $value) {
            if ($key === $value) {
                $identical++;
            }
        }

        echo "  ", $identical, " entries identical to the source",
            $identical > count($messages) / 2 ? "  <- suspicious\n" : " (proper nouns and codes)\n";
    }
}

echo "\n", $problems === 0 ? "No problems.\n" : "{$problems} problem(s).\n";
exit($problems > 0 ? 1 : 0);
