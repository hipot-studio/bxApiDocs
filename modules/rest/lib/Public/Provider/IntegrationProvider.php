<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider;

use Bitrix\Rest\Service\ServiceContainer;

class IntegrationProvider
{
	public function getCount()
	{
		return ServiceContainer::getInstance()->getIntegrationService()->getCount();
	}
}