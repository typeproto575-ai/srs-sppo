<?php
$path = $argv[1] ?? 'confirmation.php';
$content = file_get_contents($path);

// Strip PHP tags and strings to count only code braces.
// Remove <?php ... ?> and <?= ... ?> blocks entirely (they're code, keep their braces? no - remove entirely to see HTML-only braces).
// Better: count all { and } in the file, then subtract those inside strings/comments.
// Simple approach: remove strings (single, double, heredoc) and comments, then count.
$content = preg_replace('/\/\/.*$/m', '', $content); // line comments
// Remove strings: single-quoted, double-quoted (with escapes), heredoc
$content = preg_replace('~'(?:[^'\\]|\\.)*'~s, '', $content); // single-quoted
$content = preg_replace('~"(?:[^"\\\\]|\\.)*"~s', '', $content); // double-quoted
// Also remove __halt_compiler etc. Not needed here.

$open = substr_count($content, '{');
$close = substr_count($content, '}');
echo "OPEN: $open  CLOSE: $close  DIFF: " . ($open - $close) . PHP_EOL;
