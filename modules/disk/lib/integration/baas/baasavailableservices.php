<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\Baas;

use Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler;
use Bitrix\Baas\Entity\Service;

class BaasAvailableServices
{
	/**
	 * @return Service[]
	 */
	public static function get(): array
	{
		$availableServices = [];

		$service =
			BaasFactory
				::getBaasInstance()
				?->getServiceManager()
				?->getByCode(BaasSessionBoostService::SERVICE_CODE)
		;

		if ($service?->isAvailable() && OnlyOfficeHandler::isEnabled())
		{
			$availableServices[$service->getCode()] = $service;
		}

		return $availableServices;
	}
}