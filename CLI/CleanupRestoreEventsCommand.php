<?php

declare(strict_types=1);

namespace openvk\CLI;

use openvk\Web\Models\Restoration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cleanup-restore-events')]
class CleanupRestoreEventsCommand extends Command
{
    protected function configure(): void
    {
        $this->setDescription("Remove retained references after the restore window")
            ->addOption("dry-run", null, InputOption::VALUE_NONE)
            ->addOption("limit", null, InputOption::VALUE_REQUIRED, "Maximum events per run", 100);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = (new Restoration())->cleanup(max(1, (int) $input->getOption("limit")), (bool) $input->getOption("dry-run"));
        $output->writeln("$count restore events " . ($input->getOption("dry-run") ? "eligible" : "processed"));
        return Command::SUCCESS;
    }

    public function executeCleanup(): void
    {
        (new Restoration())->cleanup();
    }
}
