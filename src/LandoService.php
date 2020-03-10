<?php

namespace Maestro;

use Symfony\Component\Process\Process;
use Symfony\Component\HttpClient\HttpClient;

class LandoService {

    protected $url = '';

    public function Running() {
        $process = new Process(['lando', 'info']);
        $process->run();
        $info = $process->getOutput();
        preg_match_all("/(http:\/\/.+)\'/m", $info, $matches, PREG_SET_ORDER, 0);

        if (empty($matches[0][1])) {
            return false;
        } else {
            $this->url = $matches[0][1];
            return true;
        }
    }

    public function SiteRunning() {
        $httpClient = HttpClient::create();
        $response = $httpClient->request('GET', $this->url);

        return $response->getStatusCode() == 200;
    }

}