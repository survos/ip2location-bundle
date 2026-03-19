<?php
declare(strict_types=1);

use function Castor\import;

import(__DIR__ . '/castor');

/**
 * Castor 1.0 script to create a trivial Ip2Location demo project.
 *
 * Usage:
 *   vendor/bin/castor ip2demo
 *   vendor/bin/castor ip2demo --project=Ip2locationDemo --api-key=YOUR_KEY
 */

use Castor\Attribute\AsTask;

use function Castor\io;
use function Castor\capture;

use Symfony\Component\Filesystem\Filesystem;

use function Castor\context;
use function Castor\run as castor_run;
use Castor\Attribute\AsOption;


#[AsTask(description: 'Welcome to Castor!')]
function hello(): void
{
    $currentUser = capture('whoami');

    io()->title(sprintf('Hello %s!', $currentUser));
}


/* --------------------- helpers --------------------- */

function fs(): Filesystem
{
    static $fs;
    return $fs ??= new Filesystem();
}

function run_cwd(array|string $cmd, ?string $cwd = null): void
{
    $ctx = context();
    if ($cwd) $ctx = $ctx->withWorkingDirectory($cwd);
    castor_run($cmd, context: $ctx);
}

function capture_cwd(array|string $cmd, ?string $cwd = null): string
{
    $ctx = context();
    if ($cwd) $ctx = $ctx->withWorkingDirectory($cwd);
    return castor_run($cmd, context: $ctx)->getOutput();
}

function ensure_file_with_contents(string $path, string $contents): void
{
    fs()->mkdir(\dirname($path));
    fs()->dumpFile($path, $contents);
}

function replace_in_file(string $path, string $search, string $replace): void
{
    $s = @file_get_contents($path);
    if ($s === false) return;
    $n = str_replace($search, $replace, $s);
    if ($n !== $s) fs()->dumpFile($path, $n);
}

/* --------------------- main task --------------------- */
