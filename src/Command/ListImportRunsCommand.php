<?php

namespace App\Command;

use App\Entity\ImportRun;
use App\Repository\ImportRunRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What happened in the last import runs, without digging into logs (ADR 0012).
 */
#[AsCommand(name: 'app:inscriptions:runs', description: 'Lists the last import runs and their problems')]
final class ListImportRunsCommand extends Command
{
    public function __construct(
        private readonly ImportRunRepository $runs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'How many runs to show', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = filter_var($input->getOption('limit'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $limit) {
            $io->error('--limit must be a positive integer.');

            return Command::INVALID;
        }
        $runs = $this->runs->findBy([], ['id' => 'DESC'], $limit);

        $io->table(
            ['#', 'Started', 'Status', 'Seen', 'Created', 'Updated', 'Removed', 'Unreadable', 'Reason'],
            array_map(static fn (ImportRun $run): array => [
                $run->getId(),
                $run->getStartedAt()->format('Y-m-d H:i:s'),
                $run->getStatus()->value,
                $run->getSeen(),
                $run->getCreated(),
                $run->getUpdated(),
                $run->getRemoved(),
                $run->getUnreadable(),
                $run->getReason() ?? '',
            ], $runs),
        );

        foreach ($runs as $run) {
            if ([] !== $run->getProblems()) {
                $io->section(\sprintf('Run #%d: unreadable submissions', $run->getId()));
                $io->listing(array_map(
                    static fn (array $problem): string => ($problem['submission'] ?? '(no id)').': '.$problem['reason'],
                    $run->getProblems(),
                ));
            }
        }

        return Command::SUCCESS;
    }
}
