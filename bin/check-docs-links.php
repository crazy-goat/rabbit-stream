<?php

declare(strict_types=1);

$root = $argv[1] ?? 'docs/en';

if (!is_dir($root)) {
    fwrite(STDERR, "Usage: php bin/check-docs-links.php [docs-root]\n");
    exit(2);
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

$errors = [];
$headingSlugs = static function (string $content): array {
    $slugs = [];
    $counts = [];
    $lines = explode("\n", str_replace("\r\n", "\n", $content));
    $previousLine = null;
    $fenceCharacter = null;
    $fenceLength = 0;

    foreach ($lines as $line) {
        if ($fenceCharacter !== null) {
            if (preg_match('/^ {0,3}(' . preg_quote($fenceCharacter, '/') . '{' . $fenceLength . ',})\\s*$/', $line)) {
                $fenceCharacter = null;
                $fenceLength = 0;
            }
            continue;
        }
        if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $fenceMatch)) {
            $fenceCharacter = $fenceMatch[1][0];
            $fenceLength = strlen($fenceMatch[1]);
            $previousLine = null;
            continue;
        }

        $heading = null;
        if (preg_match('/^ {0,3}#{1,6}\\s+(.+?)\\s*#*\\s*$/u', $line, $matches)) {
            $heading = $matches[1];
        } elseif ($previousLine !== null && preg_match('/^ {0,3}(?:=+|-+)\\s*$/', $line)) {
            $heading = trim($previousLine);
        }

        if ($heading !== null) {
            $heading = html_entity_decode($heading, ENT_QUOTES | ENT_HTML5);
            $heading = preg_replace('/!?\\[([^\\]]+)\\]\\([^)]+\\)/u', '$1', $heading) ?? $heading;
            $heading = strip_tags($heading);
            $heading = preg_replace('/[`*_~]/u', '', $heading) ?? $heading;
            $slug = mb_strtolower($heading, 'UTF-8');
            $slug = preg_replace('/[^\\p{L}\\p{N}\\p{M}_\\- ]/u', '', $slug) ?? $slug;
            $slug = preg_replace('/\\s+/u', '-', trim($slug)) ?? $slug;
            $count = $counts[$slug] ?? 0;
            $counts[$slug] = $count + 1;
            $slugs[] = $count === 0 ? $slug : $slug . '-' . $count;
        }

        $previousLine = trim($line) === '' ? null : $line;
    }

    return $slugs;
};

foreach ($files as $file) {
    if (!$file instanceof SplFileInfo) {
        continue;
    }
    if ($file->getExtension() !== 'md') {
        continue;
    }
    $content = file_get_contents($file->getPathname());
    if ($content === false) {
        continue;
    }
    $lines = explode("\n", str_replace("\r\n", "\n", $content));
    foreach ($lines as $lineno => $line) {
        if (!preg_match_all('/\[[^\]]+\]\(([^)#\s]*)(#[^)\s]*)?\)/', $line, $matches, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($matches as $match) {
            $target = $match[1];
            $anchor = isset($match[2]) ? substr($match[2], 1) : '';
            if (preg_match('#^[a-z][a-z0-9+.-]*://|^mailto:#', $target)) {
                continue;
            }
            $base = $file->getPath();
            $targetPath = $target === '' ? $file->getPathname() : $target;
            $resolved = $target === ''
                ? realpath($file->getPathname())
                : realpath($targetPath[0] === '/' ? substr($targetPath, 1) : $base . '/' . $targetPath);
            if ($resolved === false || !file_exists($resolved)) {
                $errors[] = sprintf(
                    "%s:%d: %s -> %s%s\n",
                    $file->getPathname(),
                    $lineno + 1,
                    trim($line),
                    $target,
                    $anchor === '' ? '' : '#' . $anchor
                );
                continue;
            }

            if ($anchor !== '' && str_ends_with($resolved, '.md')) {
                $targetContent = file_get_contents($resolved);
                if ($targetContent === false || !in_array($anchor, $headingSlugs($targetContent), true)) {
                    $errors[] = sprintf(
                        "%s:%d: %s -> missing anchor #%s in %s\n",
                        $file->getPathname(),
                        $lineno + 1,
                        trim($line),
                        $anchor,
                        $targetPath
                    );
                }
            }
        }
    }
}

if ($errors) {
    fwrite(STDERR, "Broken docs links:\n");
    fwrite(STDERR, implode("", $errors));
    fwrite(STDERR, "\n" . count($errors) . " broken link(s) found\n");
    exit(1);
}

echo "All relative links in {$root} resolve.\n";
exit(0);
