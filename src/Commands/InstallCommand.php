<?php
namespace Maestro\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

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

        $client = HttpClient::create();
        $response = $client->request('GET', 'https://api.github.com/repos/dof-dss/nidirect-drupal/tags');

        $content = $response->getContent();
        $output->writeln($content);

        return 1;
    }
}