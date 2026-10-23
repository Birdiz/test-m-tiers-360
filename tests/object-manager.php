<?php

// Gives phpstan-doctrine the entity manager, so it checks the DQL and infers what queries return (ADR 0015).

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// The test environment, like the container phpstan-symfony reads (symfony.containerXmlPath).
$_SERVER['APP_ENV'] = 'test';
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$kernel = new Kernel('test', true);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
