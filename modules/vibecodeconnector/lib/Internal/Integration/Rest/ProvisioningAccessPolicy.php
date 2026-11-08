<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Rest;

use Bitrix\Main;
use Bitrix\Rest\Engine\Access;
use Bitrix\Rest\Marketplace\Client;
use Bitrix\Vibecodeconnector\Internal\Exception\ProvisioningFailedException;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\PaidTariff;

class ProvisioningAccessPolicy
{
	public const ERROR_CODE = 'SUBSCRIPTION_REQUIRED';
	public const PAID_TARIFF_ERROR_CODE = 'PAID_TARIFF_REQUIRED';
	protected const SUBSCRIPTION_REQUIRED_REGION = ['ru'];
	protected Main\License $license;
	protected PurchaseUrlProvider $purchaseUrlProvider;
	protected PaidTariff $paidTariff;

	public function __construct(
		?Main\License $license = null,
		?PurchaseUrlProvider $purchaseUrlProvider = null,
		?PaidTariff $paidTariff = null,
	)
	{
		$this->license = $license ?? Main\Application::getInstance()->getLicense();
		$this->purchaseUrlProvider = $purchaseUrlProvider ?? new PurchaseUrlProvider($this->license);
		$this->paidTariff = $paidTariff ?? new PaidTariff($this->license);
	}

	public function ensureAvailable(bool $requirePaidTariff): void
	{
		$isMarketRegion = in_array($this->license->getRegion(), self::SUBSCRIPTION_REQUIRED_REGION, true);

		if ($requirePaidTariff && !$this->paidTariff->isActive())
		{
			throw new ProvisioningFailedException(
				'Paid tariff required',
				self::PAID_TARIFF_ERROR_CODE,
				$this->getPurchaseData(
					$this->purchaseUrlProvider->getTariffBuyUrl(),
					$isMarketRegion ? $this->purchaseUrlProvider->getSubscriptionBuyUrl() : null,
				),
			);
		}

		$this->ensureRestModuleAvailable();

		if (!Access::isFeatureEnabled())
		{
			throw new ProvisioningFailedException('REST is unavailable on the current tariff', 'REST_UNAVAILABLE');
		}

		if ($isMarketRegion && !$this->hasMarketSubscription())
		{
			throw new ProvisioningFailedException(
				'Marketplace subscription required',
				self::ERROR_CODE,
				$this->getPurchaseData(
					$this->purchaseUrlProvider->getSubscriptionBuyUrl(),
					$this->purchaseUrlProvider->getTariffBuyUrl(),
				),
			);
		}
	}

	public function ensureRestModuleAvailable(): void
	{
		if (!Main\Loader::includeModule('rest'))
		{
			throw new ProvisioningFailedException('REST module is unavailable', 'REST_UNAVAILABLE');
		}
	}

	protected function hasMarketSubscription(): bool
	{
		return Client::isSubscriptionAvailable();
	}

	protected function getPurchaseData(?string $ctaTarget, ?string $secondaryCtaTarget): ?array
	{
		return array_filter([
			'cta' => $ctaTarget === null ? null : 'buy',
			'ctaTarget' => $ctaTarget,
			'secondaryCta' => $secondaryCtaTarget === null ? null : 'buy',
			'secondaryCtaTarget' => $secondaryCtaTarget,
		]) ?: null;
	}
}
