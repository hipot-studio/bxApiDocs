<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\Baas;

use Bitrix\Baas\Entity\Service;
use Bitrix\Disk\Internal\Service\Document\OfficeEditorAvailability;

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

		if ($service?->isAvailable() && OfficeEditorAvailability::isAvailable())
		{
			$availableServices[$service->getCode()] = $service;
		}

		return $availableServices;
	}
}