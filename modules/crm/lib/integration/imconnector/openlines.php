<?php

namespace Bitrix\Crm\Integration\ImConnector;

use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\Loader;

final class Openlines
{
	use Singleton;

	public function getList(string $connectorId, bool $withConnector = false): array
	{
		if (!Loader::includeModule('imconnector'))
		{
			return [];
		}

		return (new \Bitrix\ImConnector\Controller\Openlines())->listAction($connectorId, $withConnector);
	}
}
