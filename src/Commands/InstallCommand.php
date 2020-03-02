<?php
namespace Maestro\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Command\LockableTrait;

class InstallCommand extends Command
{
    protected $appPath;
    protected $drupalPath;
    protected $timeout;
    protected $fileSystem;
    protected $httpClient;
    use LockableTrait;

    public function __construct($settings, string $name = null)
    {
        $this->drupalPath = $settings['drupal_root'];
        $this->timeout = $settings['timeout'];
        $this->appPath = getcwd();
        $this->fileSystem = new Filesystem();
        $this->httpClient = HttpClient::create();

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
            $io->warning('The command is already running in another process');
            return 0;
        }

        $io = new SymfonyStyle($input, $output);

        // Run a Lando Info command so we can extract the site URL and
        // verify that lando is running.
        $io->writeln('Verifing that Lando is running and site is available');
        $process = new Process(['lando', 'info']);
        $process->run();
        $info = $process->getOutput();
        preg_match_all("/(http:\/\/.+)\'/m", $info, $matches, PREG_SET_ORDER, 0);

        if (empty($matches[0][1])) {
            $io->error('It doesn\'t look like you have a running Lando site');
            return 0;
        }

        // HTTP request to verify that the site is running.
        $io->writeln('Checking if site is running');
        $response = $this->httpClient->request('GET', $matches[0][1]);

        if ($response->getStatusCode() !== 200) {
            $io->warning('Lando site unavailable (404), some commands may not run properly or at all');
        }

        // Extract details for the sites that we can install.
        $content = file_get_contents('./sites.json');
        $sites = json_decode($content, TRUE);

        if ($sites === null && json_last_error() !== JSON_ERROR_NONE) {
            $io->caution('Unable to parse the sites.json file');
            return 0;
        }

        if (count($sites) > 1) {
            $requested_site = $io->choice('Please select a site to install', array_column($sites, 'name'));
            $requested_site = $sites[array_search($requested_site, array_column($sites, 'name'))];
        } elseif (count($sites) === 1) {
            $requested_site = $sites[0];
        } else {
            $io->error('Unable to fetch site details from sites.json');
            return 0;
        }

        // Request release tags for the site repo.
        $response = $this->httpClient->request('GET', 'https://api.github.com/repos/' . $requested_site['repo_path'] . '/tags');

        $content = $response->getContent();
        $releases = json_decode($content);

        if ($releases === null && json_last_error() !== JSON_ERROR_NONE) {
            $io->error('Unable to parse the releases data');
            return 0;
        }

        $release_names = [];

        foreach ($releases as $release) {
            $release_names[] = $release->name;
        }

        $requested_release = $io->choice('Please select a ' . $requested_site['name'] . ' release to install', $release_names);

        // Check for existing site installs and prompt user to continue or exit.
        if ($this->fileSystem->exists($this->drupalPath)) {

            if (!$io->confirm('The Drupal directory exists and will be overwritten, do you want to continue?', TRUE)) {
                $io->writeln('Aborting install');
                return 0;
            }

            $io->writeln('Deleting existing Drupal directory');
            $this->fileSystem->remove([$this->drupalPath]);
        }

        // Clone the site URL release branch.
        $io->writeln('Cloning release: ' . $requested_release);
        $process = new Process(['git', 'clone', 'git@github.com:' . $requested_site['repo_path'] . '.git', $this->drupalPath, '--branch', $requested_release]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        // If we have a drupal.settings.php file, copy to the cloned repo.
        if ($this->fileSystem->exists($this->appPath . '/drupal.settings.php')) {
            $io->writeln('Copying Drupal settings file to new release');
            $this->fileSystem->copy($this->appPath . '/drupal.settings.php', $this->appPath . '/' . $this->drupalPath . '/web/sites/default/settings.php', true);
        }

        // The Process component doesn't profile a good way of chaining commands.
        // Each will run as a separate shell instance.
        foreach ($requested_site['commands'] as $id => $command) {
            $io->writeln('Running ' . $id);
            $process = new Process(explode(' ', $command));
            $process->setWorkingDirectory($this->drupalPath);
            $process->run();

            while ($process->isRunning()) {
                if ($process->isSuccessful()) {
                    break;
                } else {
                    throw new ProcessFailedException($process);
                }
            }
        }

        $io->success('Install complete');
        $this->release();
        
        return 1;
    }
}