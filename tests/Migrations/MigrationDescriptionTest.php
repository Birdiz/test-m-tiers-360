<?php

namespace App\Tests\Migrations;

use Doctrine\Migrations\AbstractMigration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `doctrine:migrations:generate` stubs an empty description: every migration must say what it changes and why.
 */
final class MigrationDescriptionTest extends TestCase
{
    #[DataProvider('migrations')]
    public function testMigrationHasADescription(string $class): void
    {
        $migration = (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        self::assertNotSame('', trim($migration->getDescription()), $class.' needs a getDescription()');
    }

    /** @return iterable<string, array{class-string<AbstractMigration>}> */
    public static function migrations(): iterable
    {
        foreach (glob(dirname(__DIR__, 2).'/migrations/Version*.php') ?: [] as $file) {
            require_once $file;
            $class = 'DoctrineMigrations\\'.basename($file, '.php');

            yield basename($file) => [$class];
        }
    }
}
