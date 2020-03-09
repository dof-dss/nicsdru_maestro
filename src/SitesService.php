<?php
namespace Maestro;

use Symfony\Component\HttpClient\HttpClient;

class SitesService
{

    public static function getSites()
    {
        $http_client = HttpClient::create();

        // Extract details for the sites that we can install.
        $response = $http_client->request('GET', 'https://raw.githubusercontent.com/dof-dss/nicsdru_maestro/development/sites.json');
        if ($response->getStatusCode() !== 200) {
            throw new \Exception($response->getContent());
        }

        $sites = json_decode($response->getContent(), TRUE);

        if ($sites === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Unable to parse the sites.json file');
        }

        return $sites;
    }
}