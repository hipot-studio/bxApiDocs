<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Admission\Strategy\APAuth;

use Bitrix\Rest\Contract\Service\APAuth\PasswordService;
use Bitrix\Rest\Infrastructure\Market\MarketSubscription;
use Bitrix\Rest\Internal\Exception\Payment\MarketSubscriptionRequiredException;
use Bitrix\Rest\Internal\Exception\Payment\RestUnavailableException;
use Bitrix\Rest\Internal\Integration\Bitrix24\TariffAccessService;
use Bitrix\Rest\Internal\Service\Admission\DenialStrategyInterface;
use Bitrix\Rest\Internal\Service\Admission\Verdict;
use Bitrix\Rest\Marketplace\Client;

final class MarketSubscriptionStrategy implements DenialStrategyInterface
{
	public function __construct(
		private readonly int $passwordId,
		private readonly TariffAccessService $tariffAccessService,
		private readonly PasswordService $passwordService,
		private readonly ?\CRestServer $restServer,
	)
	{
	}

	public function resolve(): Verdict
	{
		if (Client::isSubscriptionAvailable())
		{
			return Verdict::grant();
		}

		$isFeatureEnabled = $this->tariffAccessService->isRestAccessAvailableByTariffFeatures();
		if ($isFeatureEnabled && !MarketSubscription::createByDefault()->isRequiredSubscriptionModelStarted())
		{
			return Verdict::grant();
		}

		if (
			$isFeatureEnabled
			&& $this->passwordService->isSystemPasswordById($this->passwordId)
			&& $this->isSystemMethodContext()
		)
		{
			return Verdict::grant();
		}

		if (!$isFeatureEnabled)
		{
			return Verdict::deny(new RestUnavailableException());
		}

		return Verdict::deny(new MarketSubscriptionRequiredException());
	}

	private function isSystemMethodContext(): bool
	{
		return $this->restServer === null
			|| in_array($this->restServer->getMethod(), SystemPasswordAllowedMethods::METHODS, true);
	}
}
