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
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

class InstallCommand extends Command
{
    protected $drupalRoot = 'drupal8';

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

        $filesystem = new Filesystem();

        if ($filesystem->exists($this->drupalRoot)) {

            $overwrite_question = new ConfirmationQuestion('The Drupal directory exists and will be overwritten, do you want to continue? (Y/n) ', true);

            if (!$helper->ask($input, $output, $overwrite_question)) {
                $output->writeln('Aborting install.');
                return 0;
            }

            $output->writeln('Deleting existing Drupal directory.');
            $filesystem->remove([$this->drupalRoot]);
        }

        $output->writeln(sprintf('Cloning release: %s', $requested_release));

        $process = new Process(['git', 'clone', 'git@github.com:dof-dss/nidirect-drupal.git', 'drupal8', '--branch', $requested_release]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return 1;
    }
}