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
    protected $settings;
    protected $root_path;

    public function __construct($settings, string $name = null)
    {
        $this->settings = $settings;
        $this->root_path = getcwd();
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('install')
             ->setDescription('Install a site release');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $filesystem = new Filesystem();
        $client = HttpClient::create();

        $process = new Process(['lando', 'info']);
        $process->run();
        $info = $process->getOutput();
        preg_match_all("/(http:\/\/.+)\'/m", $info, $matches, PREG_SET_ORDER, 0);

        if (empty($matches[0][1])) {
            $output->writeln('<error>It doesn\'t look like you have a running Lando site.</>');
            return 0;
        }

        $response = $client->request('GET', $matches[0][1]);

        if ($response->getStatusCode() !== 200) {
            $output->writeln('<error>It doesn\'t look like the Lando site is running properly. Response was: ' . $response->getStatusCode() . '</>');
            return 0;
        }

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

        if ($filesystem->exists($this->settings['drupal_root'])) {

            $overwrite_question = new ConfirmationQuestion('<question>The Drupal directory exists and will be overwritten, do you want to continue? (Y/n) </>', true);

            if (!$helper->ask($input, $output, $overwrite_question)) {
                $output->writeln('Aborting install.');
                return 0;
            }

            $output->writeln('<comment>Deleting existing Drupal directory.</>');
            $filesystem->remove([$this->settings['drupal_root']]);
        }

        $output->writeln('<processing>Cloning release: ' . $requested_release . '</>');

        $process = new Process(['git', 'clone', 'git@github.com:' . $requested_site['repo_path'] . '.git', 'drupal8', '--branch', $requested_release]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        if ($filesystem->exists($this->root_path . '/drupal.settings.php')) {
            $output->writeln('<processing>Copying Drupal settings file to new release</>');
            $filesystem->copy($this->root_path . '/drupal.settings.php', $this->root_path . '/' . $this->settings['drupal_root'] . '/web/sites/default/settings.php', true);
        }

        foreach ($requested_site['commands'] as $id => $command) {
            $output->writeln('<processing>Running ' . $id . '</>');
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