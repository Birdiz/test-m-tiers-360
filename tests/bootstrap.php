<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// The test database is rebuilt from the migrations, so tests also prove the migrations match the mapping.
passthru('php bin/console doctrine:database:drop --env=test --force --if-exists -q'
    .' && php bin/console doctrine:database:create --env=test -q'
    .' && php bin/console doctrine:migrations:migrate --env=test -n -q', $exitCode);
if (0 !== $exitCode) {
    exit($exitCode);
}
