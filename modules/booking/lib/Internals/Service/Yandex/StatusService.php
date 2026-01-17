<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Yandex;

use Bitrix\Booking\Internals\Service\Integration\IntegrationStatusEnum;
use Bitrix\Booking\Internals\Service\ModuleOptions;

class StatusService
{
	public function __construct(
		private readonly Account $account,
	)
	{
	}

	public function getStatus(): IntegrationStatusEnum
	{
		$isRegisteredInBookingService = $this->account->isRegistered();

		if (!$isRegisteredInBookingService)
		{
			return IntegrationStatusEnum::NotConnected;
		}

		$isRequestedFromYandex = $this->isRequestedFromYandex();

		if (!$isRequestedFromYandex)
		{
			return IntegrationStatusEnum::InProgress;
		}

		return IntegrationStatusEnum::Connected;
	}

	private function isRequestedFromYandex(): bool
	{
		return ModuleOptions::isRequestedFromYandex();
	}
}
