<?php

declare(strict_types=1);

$root = dirname(__DIR__).'/app';
$violations = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($iterator as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $contents = file_get_contents($path) ?: '';
    if (str_contains($path, DIRECTORY_SEPARATOR.'Domain'.DIRECTORY_SEPARATOR)
        && preg_match('/use (Illuminate|App\\\\Models|App\\\\Support)\\\\/', $contents) === 1) {
        $violations[] = "Domain has outward dependency: {$path}";
    }
    if (str_contains($path, DIRECTORY_SEPARATOR.'Ledger'.DIRECTORY_SEPARATOR)
        && (str_contains($contents, 'App\\Models\\Period') || str_contains($contents, 'App\\Models\\Identity') || str_contains($contents, 'Ledger\\Application\\PeriodService'))) {
        $violations[] = "Ledger bypasses Period public contract: {$path}";
    }
    if (preg_match('/namespace App\\\\([^;]+)\\\\Infrastructure;/', $contents, $match) === 1
        && preg_match('/use App\\\\(?!'.$match[1].'\\\\)([^;]+)Repository;/', $contents) === 1) {
        $violations[] = "Infrastructure imports a foreign repository: {$path}";
    }
}

if ($violations !== []) {
    fwrite(STDERR, implode(PHP_EOL, $violations).PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "Context boundaries passed.\n");
