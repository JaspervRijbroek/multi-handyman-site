<?php

// Deployer (https://deployer.org) script for the production server.
// GitHub Actions runs it on every push to main; see README "Deployment".
// By hand: dep deploy (needs DEPLOY_HOST set, and uploads your working copy).

namespace Deployer;

require 'recipe/symfony.php';

host(getenv('DEPLOY_HOST') ?: 'platform.example.com')
    ->set('remote_user', getenv('DEPLOY_USER') ?: 'deploy')
    ->set('deploy_path', '/var/www/multi-handyman-site');

set('keep_releases', 5);

// Kept between releases: .env.local, var/log (from the recipe) and uploaded photos/logos.
add('shared_dirs', ['public/uploads/projects', 'public/uploads/logos']);
add('writable_dirs', ['public/uploads/projects', 'public/uploads/logos']);
set('http_user', 'www-data');

// Upload the checked-out code with rsync, so the server needs no access to GitHub.
task('deploy:update_code', function () {
    upload(__DIR__ . '/', '{{release_path}}', [
        'options' => [
            '--exclude=.git/',
            '--exclude=.github/',
            '--exclude=.ddev/',
            '--exclude=/vendor/',
            '--exclude=/var/',
            '--exclude=/public/bundles/',
            '--exclude=/public/uploads/projects/*',
            '--exclude=/public/uploads/logos/*',
            '--exclude=/.env.local',
            '--exclude=/.env.*.local',
        ],
        'progress_bar' => false,
    ]);
});

// Production must run with APP_ENV=prod (see README "Production", step 3). Without it,
// cache:clear boots in dev and fails with "DebugBundle not found" (dev packages are skipped).
task('deploy:check_env', function () {
    if (!test('grep -qE "^APP_ENV=\"?prod\"?\\s*$" {{deploy_path}}/shared/.env.local')) {
        throw error('shared/.env.local must contain APP_ENV=prod (see README "Production").');
    }
});
before('deploy:vendors', 'deploy:check_env');

// Composer (run by the recipe) also clears and warms the cache.
after('deploy:vendors', 'database:migrate');

after('deploy:failed', 'deploy:unlock');
