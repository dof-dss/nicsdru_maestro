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
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

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
        $filesystem = new Filesystem();
        $client = HttpClient::create();
        $helper = $this->getHelper('question');

        $processingStyle = new OutputFormatterStyle('yellow', 'black', ['bold']);
        $output->getFormatter()->setStyle('processing', $processingStyle);

        $content = file_get_contents('./sites.json');

        $sites = json_decode($content, TRUE);

        if (count($sites) > 1) {
            $question_site = new ChoiceQuestion('<question>Please select a site to install</>', array_column($sites, 'name'), 0);
            $requested_site = $helper->ask($input, $output, $question_site);
            $requested_site = $sites[array_search($requested_site, array_column($sites, 'name'))];
        } elseif (count($sites) === 1) {
            $requested_site = $sites[0];
        } else {
            $output->writeln('Unable to fetch site details from sites.json');
            return 0;
        }

        $response = $client->request('GET', 'https://api.github.com/repos/' . $requested_site['repo_path'] . '/tags');

        $content = $response->getContent();
        $releases = json_decode($content);
        $release_names = [];

        foreach ($releases as $release) {
            $release_names[] = $release->name;
        }

        $question_release = new ChoiceQuestion('<question>Please select a ' . $requested_site['name'] . ' release to install</>', $release_names, 0);
        $requested_release = $helper->ask($input, $output, $question_release);

        if ($filesystem->exists($this->drupalRoot)) {

            $overwrite_question = new ConfirmationQuestion('<question>The Drupal directory exists and will be overwritten, do you want to continue? (Y/n) </>', true);

            if (!$helper->ask($input, $output, $overwrite_question)) {
                $output->writeln('Aborting install.');
                return 0;
            }

            $output->writeln('<comment>Deleting existing Drupal directory.</>');
            $filesystem->remove([$this->drupalRoot]);
        }

        $output->writeln('<processing>Cloning release: ' . $requested_release . '</>');

        $process = new Process(['git', 'clone', 'git@github.com:' . $requested_site['repo_path'] . '.git', 'drupal8', '--branch', $requested_release]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        foreach ($requested_site['commands'] as $id => $command) {
            $output->writeln('<processing>Running ' . $id . '</>');
            $output->writeln('<processing>Running ' . $command . '</>');
            $process = new Process(explode(' ', $command));
            $process->setWorkingDirectory('drupal8');
            $process->run();

            while ($process->isRunning()) {
                if ($process->isSuccessful()) {
                    break;
                } else {
                    throw new ProcessFailedException($process);
                }
            }
        }

        $output->writeln('<bg=green;fg=black;options=bold>Install complete</>');

        return 1;
    }
}