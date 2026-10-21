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
 * Meant for cron (ADR 0009). The report goes to stdout, failures and warnings to stderr, and the exit code is 1
 * only when the source failed, so cron can alert (ADR 0012).
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

        $errors = $io->getErrorStyle();
        if ([] !== $run->getProblems()) {
            $errors->warning(\sprintf('%d submission(s) of the source could not be read and were not imported.', $run->getUnreadable()));
            $errors->table(['Submission', 'Reason'], array_map(
                static fn (array $problem): array => [$problem['submission'] ?? '(no id)', $problem['reason']],
                $run->getProblems(),
            ));
        }

        if (ImportRunStatus::Failed === $run->getStatus()) {
            $errors->error((string) $run->getReason());

            return Command::FAILURE;
        }
        if (ImportRunStatus::Incomplete === $run->getStatus()) {
            $errors->warning($run->getReason().' Nothing was removed.');
        }

        return Command::SUCCESS;
    }
}
