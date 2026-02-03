<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\Baas;

use Bitrix\Baas\Entity\Service;

class BaasSessionBoostService
{
	public const SERVICE_CODE = 'disk_oo_edit';
	private ?Service $service = null;

	public function __construct()
	{
		$availableServices = BaasAvailableServices::get();

		if (isset($availableServices[self::SERVICE_CODE]))
		{
			$this->service = $availableServices[self::SERVICE_CODE];
		}
	}

	public function isActual(): bool
	{
		return (bool)$this->service?->isActual();
	}

	public function isAvailable(): bool
	{
		return (bool)$this->service?->isAvailable();
	}

	public function getQuota(): int
	{
		return (int)$this->service?->getValue();
	}
}