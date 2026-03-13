<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Integration\Rest;

use Bitrix\Market\Internal\Exception;
use Bitrix\Main\Config\Option;
use Bitrix\Main;
use Bitrix\Rest\OAuthService;
use Bitrix\Rest\AppTable;
use Bitrix\Rest\Marketplace\Client as RestClient;

class Client
{
	public function __construct()
	{

	}

	public function connectToMarket(): void
	{
		if (!Main\Loader::includeModule('rest'))
		{
			throw new Exception\RestModuleNotIncludedException();
		}

		if (OAuthService::getEngine()->isRegistered())
		{
			return;
		}

		try
		{
			OAuthService::register();
			OAuthService::getEngine()->getClient()->getApplicationList();
		}
		catch (Main\SystemException $e)
		{
			throw new Exception\RestNotRegisteredException($e);
		}

		AppTable::updateAppStatusInfo();
		RestClient::getNumUpdates();
	}
}
