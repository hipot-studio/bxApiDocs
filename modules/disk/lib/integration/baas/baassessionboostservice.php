<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\Baas;

use Bitrix\Baas\Entity\Service;
use Bitrix\Disk\Internal\Service\OnlyOffice\Promo\TariffGroup;
use Bitrix\Disk\Internal\Service\OnlyOffice\Promo\TariffGroupResolverFactory;
use Bitrix\Main\DI\ServiceLocator;

class BaasSessionBoostService
{
	public const SERVICE_CODE = 'disk_oo_edit';
	private ?Service $service = null;
	private ?TariffGroup $tariffGroup;

	public function __construct()
	{
		$availableServices = BaasAvailableServices::get();

		if (isset($availableServices[self::SERVICE_CODE]))
		{
			$this->service = $availableServices[self::SERVICE_CODE];
		}

		$this->tariffGroup =
			ServiceLocator::getInstance()
				->get(TariffGroupResolverFactory::class)
				->make()
				->resolve()
		;
	}

	public function isActual(): bool
	{
		return (bool)$this->service?->isActual();
	}

	public function isAvailable(): bool
	{
		return (bool)$this->service?->isAvailable()
			&& (bool)$this->tariffGroup?->canBuyBoost();
	}

	public function getQuota(): int
	{
		return (int)$this->service?->getValue();
	}
}