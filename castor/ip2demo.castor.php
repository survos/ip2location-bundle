<?php

declare(strict_types=1);

$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',      // bundle-level
    __DIR__ . '/../../vendor/autoload.php',   // package up one more
    __DIR__ . '/../../../vendor/autoload.php' // monorepo root
];
foreach ($autoloadCandidates as $autoload) {
    if (is_file($autoload)) { require_once $autoload; break; }
}

use Castor\Attribute\AsTask;
use Castor\Attribute\AsContext;
use Castor\Context;
use Castor\Attribute\AsOption;
use function Castor\context;
use function Castor\io;
use function Castor\run;
use Survos\StepBundle\Util\ApiKeyUtil;


const DEMO_DIR = '../demos/ip-demo';

/**
 * Default context: all tasks run from this working directory.
 * Keep it as-is (relative) — run_step() will normalize when needed.
 */
#[AsContext(default: true, name: 'app')]
function create_default_context(): Context
{
    return new Context(
        ['foo' => 'bar'],
        workingDirectory: DEMO_DIR
    );
}

/**
 * Convert $path to an absolute path. If it's already absolute, return as-is.
 * If it doesn't exist yet, we still resolve it against $base (without realpath()).
 */
function abs_path(string $path, string $base): string
{
    // unix absolute or windows drive spec
    $isAbsolute = str_starts_with($path, '/')
        || (strlen($path) > 1 && ctype_alpha($path[0]) && $path[1] === ':');

    if ($isAbsolute) {
        return $path;
    }

    $candidate = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $path;

    // Prefer realpath if it exists already (resolves .. and symlinks)
    $real = @realpath($candidate);
    if ($real !== false) {
        return $real;
    }

    // Fall back to a normalized join without touching the fs
    $parts = [];
    foreach (explode(DIRECTORY_SEPARATOR, str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate)) as $seg) {
        if ($seg === '' || $seg === '.') { continue; }
        if ($seg === '..') { array_pop($parts); continue; }
        $parts[] = $seg;
    }
    $prefix = (str_starts_with($candidate, DIRECTORY_SEPARATOR)) ? DIRECTORY_SEPARATOR : '';
    return $prefix . implode(DIRECTORY_SEPARATOR, $parts);
}

/**
 * Run a shell step with a nice title, honoring (and NOT duplicating) the context wd.
 * Castor 1.0: pass a Context (not cwd) to run().
 */
function run_step(string $title, string $command, ?string $cwd = null): void
{
    $baseCtx = context();
    $baseWd  = (string) $baseCtx->workingDirectory;
    $target  = $cwd ? abs_path($cwd, $baseWd) : abs_path($baseWd, getcwd() ?: $baseWd);

    if (!is_dir($target)) {
        mkdir($target, 0777, true);
    }

    $stepCtx = ($target === $baseWd)
        ? $baseCtx
        : $baseCtx->withWorkingDirectory($target);

    io()->section($title);
    io()->writeln(sprintf('<info>$ %s</info> (cwd: %s)', $command, $target));

    run($command, context: $stepCtx);
}

/**
 * Append/update key=value lines in .env.local for the current context project.
 */
function write_env_local(array $pairs): void
{
    $projectDir = abs_path((string) context()->workingDirectory, getcwd() ?: '.');

    if (!is_dir($projectDir)) {
        throw new RuntimeException("Project dir does not exist: $projectDir");
    }

    $envFile = $projectDir . '/.env.local';
    $lines   = is_file($envFile) ? file($envFile, FILE_IGNORE_NEW_LINES) : [];
    $map     = [];

    foreach ($lines as $line) {
        if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/', $line, $m)) {
            $map[$m[1]] = $m[2];
        }
    }
    foreach ($pairs as $k => $v) {
        $map[$k] = (string) $v;
    }

    $buf = '';
    foreach ($map as $k => $v) {
        $v = str_contains($v, ' ') ? "\"$v\"" : $v;
        $buf .= "$k=$v\n";
    }
    file_put_contents($envFile, $buf);
    io()->success(".env.local updated at $envFile");
}

/**
 * 1) Create a new Symfony app (Symfony 7.3 webapp) in the context directory.
 */
#[AsTask(name: 'app:symfony-new', description: 'Create Symfony 7.3 webapp skeleton in the context directory')]
function task_symfony_new(string $version = '7.3', bool $useCli = false): void
{
    $dir   = abs_path((string) context()->workingDirectory, getcwd() ?: '.');

    // If directory is not empty, warn and skip
    if (is_dir($dir) && count(scandir($dir) ?: []) > 2) {
        io()->warning("Directory '$dir' already exists and is not empty; skipping Symfony creation.");
        return;
    }

    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    // Create the project *inside* the context directory
    $cmd = "symfony new --webapp --version=$version --dir=.";
    run_step("Create Symfony $version webapp", $cmd);

    io()->success("Symfony app created in '$dir'.");
}


/**
 * 2) Ask/write API key(s) into .env.local (OPENAI + MEILISEARCH).
 */
#[AsTask(name: 'app:get-api-key', description: 'Ensure provider API keys are set in .env.local')]
function task_get_api_key(
    string $providers = 'flickr'
): void {
    $projectDir = (string) context()->workingDirectory;

    // Castor-friendly I/O callbacks
    $ask = fn (string $prompt, bool $hidden) => $hidden
        ? io()->askHidden($prompt)
        : io()->ask($prompt);

    $out = fn (string $msg) => io()->writeln($msg);

    // Optional: on-the-fly overrides for providers not yet in the bundle registry

    $wroteAny = false;
    foreach (array_map('trim', explode(',', $providers)) as $provider) {
        if ($provider === '') { continue; }
        $written = ApiKeyUtil::ensureFor(
            provider:   $provider,
            projectDir: $projectDir,
            ask:        $ask,
            out:        $out,
            override:   $overrides[$provider] ?? null
        );
        if ($written) {
            $wroteAny = true;
            io()->success(sprintf(
                'Saved %s for provider "%s" to .env.local',
                implode(', ', array_keys($written)),
                $provider
            ));
        } else {
            io()->note(sprintf('No changes needed for provider "%s" (already set).', $provider));
        }
    }

    if (!$wroteAny) {
        io()->note('All requested API keys were already present.');
    }
}

/**
 * 3) Install bundles & dev tools you typically use.
 */
#[AsTask(name: 'app:install-bundles', description: 'Composer require common bundles in the context project')]
function task_install_bundles(): void
{
    $req = [
        'symfony/asset-mapper',
        'survos/core-bundle:^1.0',
        'survos/meili-bundle:^1.0',
    ];

    $dev = [
        'symfony/maker-bundle --dev',
        'phpstan/phpstan --dev',
    ];

    run_step('Composer require (prod)', 'composer require ' . implode(' ', array_map('escapeshellarg', $req)));
    run_step('Composer require (dev)',  'composer require ' . implode(' ', $dev));
    io()->success('Bundles installed.');
}

/**
 * 4) Configure local env for Meili, etc.
 */
#[AsTask(name: 'app:configure-env', description: 'Write default MEILISEARCH_URL and related env entries')]
function task_configure_env(string $meiliUrl = 'http://127.0.0.1:7700'): void
{
    write_env_local([
        'MEILISEARCH_URL' => $meiliUrl,
        'DATABASE_URL' => "sqlite:///%kernel.project_dir%/var/data_%kernel.environment%.db"
    ]);
}

/**
 * 5) DB setup (optional): create database & run migrations if Doctrine is present.
 */
#[AsTask(name: 'app:db-setup', description: 'Create DB and run migrations if doctrine is installed')]
function task_db_setup(): void
{
    $projectDir   = abs_path((string) context()->workingDirectory, getcwd() ?: '.');
    $composerJson = $projectDir . '/composer.json';

    $hasDoctrine = false;
    try {
        if (is_file($composerJson)) {
            $json = json_decode(file_get_contents($composerJson) ?: 'null', true, 512, JSON_THROW_ON_ERROR);
            $pkgs = array_merge(
                array_keys($json['require'] ?? []),
                array_keys($json['require-dev'] ?? [])
            );
            $hasDoctrine = in_array('doctrine/orm', $pkgs, true) || in_array('doctrine/doctrine-bundle', $pkgs, true);
        }
    } catch (Throwable) {
        // ignore
    }

    if (!$hasDoctrine) {
        io()->note('Doctrine not detected; skipping DB setup.');
        return;
    }

    run_step('Create database', 'php bin/console doctrine:schema:update --force');
//    run_step('Run migrations', 'php bin/console doctrine:migrations:migrate -n');
}

/**
 * 6) Make a quick homepage so you see something.
 */
#[AsTask(name: 'app:make-homepage', description: 'Make a HomeController and route to "/"')]
function task_make_homepage(): void
{
    run_step('Make controller', 'php bin/console make:controller HomeController');

    $projectDir = abs_path((string) context()->workingDirectory, getcwd() ?: '.');
    $twig       = $projectDir . '/templates/home/index.html.twig';

    $msg = <<<HTML
<h1>Hello from ip2demo 👋</h1>
<p>PHP 8.4 • Symfony 7.3 • Castor tasks split per step, using Context for cwd.</p>
HTML;

    if (file_exists($twig)) {
        $tpl = <<<TWIG
{% extends 'base.html.twig' %}
{% block title %}Welcome!{% endblock %}
{% block body %}
<div class="container py-4">
    $msg
</div>
{% endblock %}
TWIG;
        file_put_contents($twig, $tpl);
        io()->success("Updated $twig");
    }
}

/**
 * 7) Compile assets (AssetMapper) – harmless if not configured.
 */
#[AsTask(name: 'app:assets-build', description: 'Compile asset map (safe to run even without assets)')]
function task_assets_build(): void
{
    run_step('Compile asset map', 'php bin/console asset-map:compile');
}

/**
 * Orchestrator: run all steps in order, using the current context workingDirectory.
 */
#[AsTask(name: 'app:create-demo', description: 'Run all app:* steps to create the demo in the context directory')]
function task_create_demo(string $symfonyVersion = '7.3', bool $useCli = false): void
{
    task_symfony_new(version: $symfonyVersion, useCli: $useCli);
    task_get_api_key();
    task_install_bundles();
    task_configure_env();
    task_db_setup();
    task_make_homepage();
    task_assets_build();

    io()->success(sprintf(
        "All done! cd %s && symfony serve -d (or your preferred server).",
        abs_path((string) context()->workingDirectory, getcwd() ?: '.')
    ));
}

/**
 * Utility: show the current context working directory (handy for debugging).
 */
#[AsTask(name: 'app:ctx', description: 'Show current context workingDirectory')]
function task_show_ctx(): void
{
    io()->success('Context workingDirectory: ' . abs_path((string) context()->workingDirectory, getcwd() ?: '.'));
}
