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
use Symfony\Component\Console\Command\LockableTrait;

class InstallCommand extends Command
{
    protected $settings;
    protected $rootpath;
    protected $filesystem;
    protected $httpclient;
    use LockableTrait;

    public function __construct($settings, string $name = null)
    {
        $this->settings = $settings;
        $this->rootpath = getcwd();
        $this->filesystem = new Filesystem();
        $this->httpclient = HttpClient::create();
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('install')
             ->setDescription('Install a site release');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');
            return 0;
        }

        $helper = $this->getHelper('question');

        $processingStyle = new OutputFormatterStyle('yellow', 'black', ['bold']);
        $output->getFormatter()->setStyle('processing', $processingStyle);

        // Run a Lando Info command so we can extract the site URL and
        // verify that lando is running.
        $output->writeln('Verifing that Lando is running and site is available.');
        $process = new Process(['lando', 'info']);
        $process->run();
        $info = $process->getOutput();
        preg_match_all("/(http:\/\/.+)\'/m", $info, $matches, PREG_SET_ORDER, 0);

        if (empty($matches[0][1])) {
            $output->writeln('<error>It doesn\'t look like you have a running Lando site.</>');
            return 0;
        }

        // HTTP request to verify that the site is running.
        $response = $this->httpclient->request('GET', $matches[0][1]);

        if ($response->getStatusCode() !== 200) {
            $output->writeln('<error>It doesn\'t look like the Lando site is running properly. Response was: ' . $response->getStatusCode() . '</>');
            return 0;
        }

        // Extract details for the sites that we can install.
        $content = file_get_contents('./sites.json');
        $sites = json_decode($content, TRUE);

        if ($sites === null && json_last_error() !== JSON_ERROR_NONE) {
            $output->writeln('<error>Unable to parse the sites.json file</>');
            return 0;
        }

        if (count($sites) > 1) {
            $question_site = new ChoiceQuestion('<question>Please select a site to install</>', array_column($sites, 'name'), 0);
            $requested_site = $helper->ask($input, $output, $question_site);
            $requested_site = $sites[array_search($requested_site, array_column($sites, 'name'))];
        } elseif (count($sites) === 1) {
            $requested_site = $sites[0];
        } else {
            $output->writeln('<error>Unable to fetch site details from sites.json</>');
            return 0;
        }

        // Request release tags for the site repo.
        $response = $this->httpclient->request('GET', 'https://api.github.com/repos/' . $requested_site['repo_path'] . '/tags');

        $content = $response->getContent();
        $releases = json_decode($content);

        if ($releases === null && json_last_error() !== JSON_ERROR_NONE) {
            $output->writeln('<error>Unable to parse the releases data</>');
            return 0;
        }

        $release_names = [];

        foreach ($releases as $release) {
            $release_names[] = $release->name;
        }

        $question_release = new ChoiceQuestion('<question>Please select a ' . $requested_site['name'] . ' release to install</>', $release_names, 0);
        $requested_release = $helper->ask($input, $output, $question_release);

        // Check for existing site installs and prompt user to continue or exit.
        if ($this->filesystem->exists($this->settings['drupal_root'])) {

            $overwrite_question = new ConfirmationQuestion('<question>The Drupal directory exists and will be overwritten, do you want to continue? (Y/n) </>', true);

            if (!$helper->ask($input, $output, $overwrite_question)) {
                $output->writeln('Aborting install.');
                return 0;
            }

            $output->writeln('<comment>Deleting existing Drupal directory.</>');
            $this->filesystem->remove([$this->settings['drupal_root']]);
        }

        // Clone the site URL release branch.
        $output->writeln('<processing>Cloning release: ' . $requested_release . '</>');
        $process = new Process(['git', 'clone', 'git@github.com:' . $requested_site['repo_path'] . '.git', 'drupal8', '--branch', $requested_release]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        // If we have a drupal.settings.php file, copy to the cloned repo.
        if ($this->filesystem->exists($this->rootpath . '/drupal.settings.php')) {
            $output->writeln('<processing>Copying Drupal settings file to new release</>');
            $this->filesystem->copy($this->rootpath . '/drupal.settings.php', $this->rootpath . '/' . $this->settings['drupal_root'] . '/web/sites/default/settings.php', true);
        }

        // The Process component doesn't profile a good way of chaining commands.
        // Each will run as a separate shell instance.
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
        $this->release();
        
        return 1;
    }
}