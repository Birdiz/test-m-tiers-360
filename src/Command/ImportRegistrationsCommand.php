<?php

namespace App\Command;

use App\Entity\ImportRunStatus;
use App\Import\RegistrationImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Meant for cron (ADR 0009). Exits with 1 only when the source failed, so cron can alert.
 */
#[AsCommand(name: 'app:inscriptions:import', description: 'Imports the registrations of the form API')]
final class ImportRegistrationsCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly RegistrationImporter $importer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->lock()) {
            $io->note('Another import run is in progress.');

            return Command::SUCCESS;
        }

        $run = $this->importer->run();
        $this->release();

        $io->definitionList(
            ['Status' => $run->getStatus()->value],
            ['Seen' => $run->getSeen()],
            ['Created' => $run->getCreated()],
            ['Updated' => $run->getUpdated()],
            ['Removed' => $run->getRemoved()],
            ['Unreadable' => $run->getUnreadable()],
        );

        return match ($run->getStatus()) {
            ImportRunStatus::Failed => $this->fail($io, (string) $run->getError()),
            ImportRunStatus::Incomplete => $this->warn($io, (string) $run->getError()),
            default => Command::SUCCESS,
        };
    }

    private function fail(SymfonyStyle $io, string $error): int
    {
        $io->error($error);

        return Command::FAILURE;
    }

    private function warn(SymfonyStyle $io, string $reason): int
    {
        $io->warning($reason.' Nothing was removed.');

        return Command::SUCCESS;
    }
}
