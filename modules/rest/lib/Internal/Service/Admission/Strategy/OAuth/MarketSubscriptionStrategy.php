<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Admission\Strategy\OAuth;

use Bitrix\Main\ModuleManager;
use Bitrix\Rest\AppTable;
use Bitrix\Rest\Infrastructure\Market\MarketSubscription;
use Bitrix\Rest\Internal\Exception\Payment\MarketSubscriptionRequiredException;
use Bitrix\Rest\Internal\Exception\Payment\RestUnavailableException;
use Bitrix\Rest\Internal\Integration\Bitrix24\TariffAccessService;
use Bitrix\Rest\Internal\Service\Admission\DenialStrategyInterface;
use Bitrix\Rest\Internal\Service\Admission\Verdict;
use Bitrix\Rest\Marketplace\Client;
use Bitrix\Rest\Marketplace\Immune;

final class MarketSubscriptionStrategy implements DenialStrategyInterface
{
	private static array $deniedExceptionClass = [];

	public function __construct(
		private readonly string $clientId,
		private readonly TariffAccessService $tariffAccessService,
	)
	{
	}

	public function resolve(): Verdict
	{
		if (!array_key_exists($this->clientId, self::$deniedExceptionClass))
		{
			self::$deniedExceptionClass[$this->clientId] = $this->calculateDeniedExceptionClass();
		}

		$exceptionClass = self::$deniedExceptionClass[$this->clientId];

		return $exceptionClass === null ? Verdict::grant() : Verdict::deny(new $exceptionClass());
	}

	public static function resetCache(): void
	{
		self::$deniedExceptionClass = [];
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

		$immuneList = Immune::getList();
		if (in_array($this->clientId, $immuneList, true))
		{
			return null;
		}

		$appInfo = AppTable::getByClientId($this->clientId);
		if ($appInfo)
		{
			if (!empty($appInfo['CODE']) && in_array($appInfo['CODE'], $immuneList, true))
			{
				return null;
			}

			if (($appInfo['STATUS'] ?? null) === AppTable::STATUS_FREE && $isFeatureEnabled)
			{
				return null;
			}
		}

		return $isFeatureEnabled ? MarketSubscriptionRequiredException::class : RestUnavailableException::class;
	}
}
