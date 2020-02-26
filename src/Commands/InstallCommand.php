<?php
namespace Maestro\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Console\Question\ChoiceQuestion;

class InstallCommand extends Command
{
    protected function configure()
    {
        $this->setName('install')
             ->setDescription('Install a site release');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $client = HttpClient::create();
        $response = $client->request('GET', 'https://api.github.com/repos/dof-dss/nidirect-drupal/tags');

        $content = $response->getContent();

        $releases = json_decode($content);
        $release_names = [];

        foreach ($releases as $release) {
            $release_names[] = $release->name;
        }

        $helper = $this->getHelper('question');
        $question_release = new ChoiceQuestion('Please select a release to install', $release_names, 0);

        $requested_release = $helper->ask($input, $output, $question_release);
        $output->writeln('You have selected the release: '. $requested_release);

        $process = new Process(['git', 'clone', 'git@github.com:dof-dss/nidirect-drupal.git', 'drupal8', '--branch', $requested_release]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return 1;
    }
}