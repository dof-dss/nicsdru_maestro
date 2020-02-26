<?php
namespace Maestro\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

class InstallCommand extends Command
{
    protected function configure()
    {
        $this->setName('install')
            ->setDescription('Install a site release')
            ->addArgument('release', InputArgument::REQUIRED, 'Release name');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($output->isVerbose()) {
            $output->writeln(sprintf('Installing release: %s', $input->getArgument('release')));
        }
        return 1;
    }
}