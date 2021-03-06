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
use Symfony\Component\Finder\Finder;
use Maestro\InstallType;
use Maestro\SitesService;
use Maestro\LandoService;

class InstallCommand extends Command
{
    use LockableTrait;

    protected $appPath;
    protected $drupalPath;
    protected $siteInfo;
    protected $timeout;
    protected $fileSystem;
    protected $httpClient;
    protected $installType;
    protected $branch;
    protected $display;
    protected $lando;

    public function __construct($settings, string $name = null)
    {
        $this->drupalPath = trim($settings['drupal_root'], '/');
        $this->timeout = $settings['timeout'];
        $this->db_import_path = trim($settings['db_import_path'], '/');
        $this->appPath = getcwd();
        $this->fileSystem = new Filesystem();
        $this->httpClient = HttpClient::create();
        $this->lando = new LandoService();

        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('install')
            ->setDescription('Install a site from a branch or release tag');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->display = new SymfonyStyle($input, $output);
        $this->display->title('Maestro site installer');

        // Prevent the command from running multiple times.
        if (!$this->lock()) {
            $this->display->warning('The command is already running in another process');
            return 0;
        }

        // Verify that lando is running.
        $this->display->text('Verifing that Lando is running and site is available');
        if (!$this->lando->Running()) {
            $this->display->error('It doesn\'t look like you have a running Lando site');
            return 0;
        }

        // Verify that the site is running.
        $this->display->text('Checking if site is running');
        if (!$this->lando->SiteRunning()) {
            $this->display->warning('Lando site unavailable (404), some commands may not run properly or at all');
        }

        // Fetch sites info.
        $sites = SitesService::getSites();

        // If we have multiple sites defined, display a choice, otherwise
        // use the first instance.
        if (count($sites) > 1) {
            $requested_site = $this->display->choice('Please select a site to install', array_column($sites, 'name'));
            $requested_site = $sites[array_search($requested_site, array_column($sites, 'name'))];
        } elseif (count($sites) === 1) {
            $requested_site = $sites[0];
        } else {
            $this->display->error('Unable to fetch site details from sites.json');
            return 0;
        }

        $this->siteInfo = (object) $requested_site;

        $this->installType = new InstallType(InstallType::toArray()[$this->display->choice('What do you want to install?', InstallType::keys())]);

        if ($this->installType == InstallType::BRANCH()) {
            $this->branch = $this->promptBranch();
        } else {
            $this->branch = $this->promptRelease();
        }

        // Check for existing site installs and prompt user to continue or exit.
        if ($this->fileSystem->exists($this->drupalPath)) {

            if (!$this->display->confirm('The Drupal directory exists and will be overwritten, do you want to continue?', TRUE)) {
                $this->display->warning('Aborting install');
                return 0;
            }

            // Create a backup of the Drupal sites directory if it exists,
            // we will copy this back into the site once cloning is complete.
            if ($this->fileSystem->exists($this->drupalPath . '/web/sites')) {
                $this->display->text('Backing up Drupal \'sites\' directory (This could take a while)');

                // If we don't have sites backup directory, create one.
                if (!$this->fileSystem->exists($this->appPath . '/sites_backup')) {
                    $this->fileSystem->mkdir($this->appPath . '/sites_backup');
                }
                $this->fileSystem->mirror($this->drupalPath . '/web/sites', $this->appPath . '/sites_backup', NULL, ['override' => TRUE]);
            }

            // Delete any existing Drupal site (clean start).
            $this->display->text('Deleting existing Drupal directory');
            $this->fileSystem->remove([$this->drupalPath]);
        }

        // Clone the site branch/release.
        $this->display->text('Cloning ' . $this->installType->getValue() . ': ' . $this->branch);
        $process = new Process(['git', 'clone', 'git@github.com:' . $this->siteInfo->repoPath . '.git', $this->drupalPath, '--branch', $this->branch]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        // Restore sites_backup directory to site if it exists.
        if ($this->fileSystem->exists($this->appPath . '/sites_backup')) {
            $this->display->text('Restoring Drupal \'sites\' directory (This could take a while)');
            $this->fileSystem->mirror($this->appPath . '/sites_backup', $this->drupalPath . '/web/sites');
            $this->fileSystem->remove([$this->appPath . '/sites_backup']);
        }

        // If we have a drupal.settings.php file, copy to the cloned repo.
        if ($this->fileSystem->exists($this->appPath . '/drupal.settings.php')) {
            $this->display->text('Copying Drupal settings file to new ' . $this->cloneType . ' site');
            $this->fileSystem->copy($this->appPath . '/drupal.settings.php', $this->appPath . '/' . $this->drupalPath . '/web/sites/default/settings.php', true);
        }

        $this->promptDatabase();

        $this->runSiteCommands();

        $this->display->success('Install complete');
        // Provide link to release notes.
        if ($this->installType == InstallType::RELEASE()) {
            $this->display->note('More information about this release can be viewed at: https://github.com/' . $this->siteInfo->repoPath . '/releases/tag/' . $this->branch);
        }

        $this->release();

        return 1;
    }

    /*
     * Display prompt to enter a branch name.
     */
    protected function promptBranch() {
        $branch = $this->display->ask('Please provide the name of the git branch');
        // Verify that branch exists on the repo.
        $response = $this->httpClient->request('GET', 'https://api.github.com/repos/' . $this->siteInfo->repoPath . '/branches/' . $branch);

        // If the branch info isn't found, prompt again.
        if ($response->getStatusCode() == '404') {
            $this->display->warning('Branch: ' . $branch . ' not found');
            $this->promptBranch();
        } else {
            return $branch;
        }
    }

    /*
     * Display a prompt to select a release version.
     */
    protected function promptRelease() {
        // Request release tags for the site repo.
        $response = $this->httpClient->request('GET', 'https://api.github.com/repos/' . $this->siteInfo->repoPath . '/tags');

        $content = $response->getContent();
        $releases = json_decode($content);

        // Limit the number of releases displayed to the latest 10.
        $releases = array_splice($releases, 0, 10);

        if ($releases === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->display->error('Unable to parse the releases data');
            return 0;
        }

        return $this->display->choice('Please select a ' . $this->siteInfo->name . ' release to install', array_column($releases, 'name'));
    }

    /*
     * Display a prompt to select database dump to import.
     */
    protected function promptDatabase() {
        if ($this->fileSystem->exists($this->appPath . '/' . $this->db_import_path)) {
            $finder = new Finder();
            $finder->files()->name('*.sql.gz')->in($this->appPath . '/' . $this->db_import_path);

            if ($finder->hasResults()) {
                $db_dumps = ['skip import'];
                foreach ($finder as $file) {
                    $db_dumps[] = $file->getFilename();
                }

                $db_requested = $this->display->choice('Select a database file to install', $db_dumps);

                if ($db_requested == 'skip import' ) {
                    return;
                } else {
                    $this->display->text('Importing database ' . $db_requested);
                    $process = new Process(['lando', 'db-import', $this->appPath . '/' . $this->db_import_path . '/' . $db_requested]);
                    $process->run();
                    $this->display->text('Database import complete');
                }
            }
        }
    }

    /*
     * Runs a series of commands retrieved from the sites info.
     */
    protected function runSiteCommands() {
        // The Process component doesn't profile a good way of chaining
        // commands and reacting to events on those.
        // Each will run as a separate shell instance one after the other.
        $this->display->section('Running commands');
        $this->display->progressStart(count($this->siteInfo->commands));

        foreach ($this->siteInfo->commands as $id => $command) {
            $this->display->newLine();
            $this->display->text('Running ' . $id);
            $process = new Process(explode(' ', $command));
            $process->setWorkingDirectory($this->drupalPath);
            $process->run();
            $this->display->progressAdvance();
        }

        $this->display->progressFinish();
    }
}