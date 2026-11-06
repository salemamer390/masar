<?php

/** Deterministic, dependency-free build. No minification or rule reordering. */
$root = dirname(__DIR__);
$parts = [
    'resources/styles/app-header.txt',
    'resources/styles/01-foundation.css',
    'resources/styles/02-components.css',
    'resources/styles/03-design-system.css',
];
$css = '';
foreach ($parts as $part) {
    $source = file_get_contents($root.'/'.$part);
    if ($source === false) { fwrite(STDERR, "Cannot read {$part}\n"); exit(1); }
    $css .= $source;
}
$target = $root.'/public/app.css';
if (in_array('--check', $argv, true)) {
    if (file_get_contents($target) !== $css) {
        fwrite(STDERR, "public/app.css is stale. Run php scripts/build-styles.php and commit source + output.\n");
        exit(1);
    }
    echo "Styles match their ordered sources.\n";
    exit(0);
}
if (file_put_contents($target, $css) === false) exit(1);
echo "Built public/app.css (".strlen($css)." bytes).\n";
