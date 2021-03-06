<?php
namespace Maestro\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Command\LockableTrait;
use Maestro\SitesService;
use Maestro\LandoService;

class UpdateCommand extends Command
{
    use LockableTrait;

    protected $appPath;
    protected $drupalPath;
    protected $siteInfo;
    protected $timeout;
    protected $display;
    protected $lando;

    public function __construct($settings, string $name = null)
    {
        $this->drupalPath = trim($settings['drupal_root'], '/');
        $this->timeout = $settings['timeout'];
        $this->appPath = getcwd();
        $this->lando = new LandoService();

        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('update')
            ->setDescription('Run update commands against a local site');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->display = new SymfonyStyle($input, $output);
        $this->display->title('Maestro site updater');

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

        $this->runSiteCommands();
        $this->display->success('Update complete');

        $this->release();

        return 1;

    }

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