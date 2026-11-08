<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Integration\Bitrix24;

use Bitrix\Bitrix24\Public\Enum\VibePlus\MonetizationModel;
use Bitrix\Bitrix24\Public\Provider\VibePlus\RestAccessProvider;
use Bitrix\Bitrix24\Public\Service\VibePlus\MonetizationModelProvider;
use Bitrix\Bitrix24\Public\Service\VibePlus\UpsellProjectionProvider;
use Bitrix\Bitrix24\Public\ValueObject\VibePlusUpsellProjection;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

class TariffAccessService
{
	public function getRestAccessAvailability(): ?bool
	{
		return $this->getRestAccessProvider()?->getAvailability();
	}

	public function isRestAccessAvailableByTariffFeatures(): bool
	{
		return $this->getRestAccessProvider()?->isAvailableByTariffFeatures() ?? false;
	}

	public function getMarketApplicationLimit(): ?int
	{
		return $this->getRestAccessProvider()?->getMarketApplicationLimit();
	}

	public function isUserWebhookDenied(): bool
	{
		return $this->getRestAccessAvailability() === false;
	}

	public function isSubscriptionMonetizationModel(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		return (new MonetizationModelProvider())->get() === MonetizationModel::SUBSCRIPTION;
	}

	public function getUpsellProjection(int $userId): ?VibePlusUpsellProjection
	{
		return $this->getUpsellProjectionProvider()?->getProjectionForUser($userId);
	}

	private function getRestAccessProvider(): ?RestAccessProvider
	{
		return $this->getService(RestAccessProvider::class);
	}

	private function getUpsellProjectionProvider(): ?UpsellProjectionProvider
	{
		return $this->getService(UpsellProjectionProvider::class);
	}

	protected function getService(string $className): ?object
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return null;
		}

		$serviceLocator = ServiceLocator::getInstance();

		return $serviceLocator->has($className) ? $serviceLocator->get($className) : null;
	}
}
