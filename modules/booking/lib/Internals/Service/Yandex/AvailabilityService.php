<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Yandex;

use Bitrix\Main\Application;

class AvailabilityService
{
	public function isAvailable(): bool
	{
		return in_array($this->getCurrentRegion(), $this->getAvailableRegions(), true);
	}

	private function getCurrentRegion(): string
	{
		return Application::getInstance()->getLicense()->getRegion() ?? 'en';
	}

	private function getAvailableRegions(): array
	{
		return [
			'ru',
			'by',
			'kz',
			'uz',
		];
	}
}
