<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Admission\Strategy\Portal;

use Bitrix\Main\ModuleManager;
use Bitrix\Rest\Infrastructure\Market\MarketSubscription;
use Bitrix\Rest\Internal\Exception\Payment\MarketSubscriptionRequiredException;
use Bitrix\Rest\Internal\Exception\Payment\RestUnavailableException;
use Bitrix\Rest\Internal\Integration\Bitrix24\TariffAccessService;
use Bitrix\Rest\Internal\Service\Admission\DenialStrategyInterface;
use Bitrix\Rest\Internal\Service\Admission\Verdict;
use Bitrix\Rest\Marketplace\Client;

final class MarketSubscriptionStrategy implements DenialStrategyInterface
{
	private static bool $cached = false;
	private static ?string $deniedExceptionClass = null;

	public function __construct(private readonly TariffAccessService $tariffAccessService)
	{
	}

	public function resolve(): Verdict
	{
		if (!self::$cached)
		{
			self::$deniedExceptionClass = $this->calculateDeniedExceptionClass();
			self::$cached = true;
		}

		$exceptionClass = self::$deniedExceptionClass;

		return $exceptionClass === null ? Verdict::grant() : Verdict::deny(new $exceptionClass());
	}

	public static function resetCache(): void
	{
		self::$cached = false;
		self::$deniedExceptionClass = null;
	}

	private function calculateDeniedExceptionClass(): ?string
	{
		if (Client::isSubscriptionAvailable())
		{
			return null;
		}

		$isFeatureEnabled = !ModuleManager::isModuleInstalled('bitrix24')
			|| $this->tariffAccessService->isRestAccessAvailableByTariffFeatures();

		if (!MarketSubscription::createByDefault()->isRequiredSubscriptionModelStarted() && $isFeatureEnabled)
		{
			return null;
		}

		return $isFeatureEnabled ? MarketSubscriptionRequiredException::class : RestUnavailableException::class;
	}
}
