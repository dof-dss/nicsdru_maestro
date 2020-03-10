<?php

namespace Maestro;

use Symfony\Component\Process\Process;

class LandoService {

    protected $siteUrl = '';

    public function Running() {
        $process = new Process(['lando', 'info']);
        $process->run();
        $info = $process->getOutput();
        preg_match_all("/(http:\/\/.+)\'/m", $info, $matches, PREG_SET_ORDER, 0);

        if (empty($matches[0][1])) {
            return false;
        } else {
            $this->siteUrl = $matches[0][1];
            return true;
        }
    }

}