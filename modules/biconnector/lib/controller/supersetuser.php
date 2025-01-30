<?php

namespace Bitrix\BIConnector\Controller;

use Bitrix\BIConnector\Integration\Superset\Integrator\ProxyIntegrator;
use Bitrix\BIConnector\Integration\Superset\SupersetController;
use Bitrix\Intranet\ActionFilter\IntranetUser;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Loader;

class supersetuser extends Controller
{
    public function getAction()
    {
        $integrator = ProxyIntegrator::getInstance();
        $superset = new SupersetController($integrator);

        $credentials = $superset->getUserCredentials();
        if (null !== $credentials) {
            return [
                'user' => [
                    'login' => $credentials->login,
                    'password' => $credentials->password,
                ],
            ];
        }

        return [];
    }

    public function configureActions()
    {
        $get = [
            '+prefilters' => [],
        ];

        if (Loader::includeModule('intranet')) {
            $get['+prefilters'][] = new IntranetUser();
        }

        return [
            'get' => $get,
        ];
    }
}
