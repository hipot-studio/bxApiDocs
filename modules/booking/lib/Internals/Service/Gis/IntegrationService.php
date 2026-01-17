<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Gis;

use Bitrix\Booking\Internals\Service\Integration\IntegrationServiceInterface;
use Bitrix\Booking\Internals\Service\Integration\IntegrationStatusEnum;
use Bitrix\Main\Application;

class IntegrationService implements IntegrationServiceInterface
{
	public function getName(): string
	{
		return 'gis';
	}

	public function getStatus(): IntegrationStatusEnum|null
	{
		return null;
	}

	public function isAvailable(): bool
	{
		$availableRegions = [
			'ru',
			'by',
			'kz',
			'uz',
			'kg',
			'am',
			'az',
			'ge',
		];

		return in_array(
			Application::getInstance()->getLicense()->getRegion() ?? 'en',
			$availableRegions,
			true
		);
	}

	public function getSettings(): array
	{
		return [];
	}
}
