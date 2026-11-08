<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Admission\Strategy;

use Bitrix\Main\Application;
use Bitrix\Rest\Internal\Exception\Admission\RestAccessDenialExceptionInterface;
use Bitrix\Rest\Internal\Exception\Payment\FeatureNotAvailableOnCurrentPlanException;
use Bitrix\Rest\Internal\Exception\Payment\MarketSubscriptionRequiredException;
use Bitrix\Rest\Internal\Exception\Payment\RestUnavailableException;
use Bitrix\Rest\Internal\Service\Admission\GrantingStrategyInterface;
use Bitrix\Rest\Public\Event\RestAccessGrantEvent;

final class EventGrantingStrategy implements GrantingStrategyInterface
{
	private const DENIAL_REASONS = [
		FeatureNotAvailableOnCurrentPlanException::class => RestAccessGrantEvent::REASON_TARIFF,
		MarketSubscriptionRequiredException::class => RestAccessGrantEvent::REASON_MARKET_SUBSCRIPTION,
		RestUnavailableException::class => RestAccessGrantEvent::REASON_TARIFF,
	];

	private readonly ?string $clientId;

	public function __construct(
		private readonly string $authType,
		?string $clientId,
		private readonly ?int $passwordId,
		private readonly ?\CRestServer $restServer,
	)
	{
		$this->clientId = $clientId === '' ? null : $clientId;
	}

	public function grants(RestAccessDenialExceptionInterface $denial): bool
	{
		try
		{
			$reason = self::DENIAL_REASONS[$denial::class] ?? null;
			if ($reason === null)
			{
				return false;
			}

			if ($this->restServer === null)
			{
				return false;
			}

			$event = new RestAccessGrantEvent(
				$this->authType,
				$reason,
				$this->clientId,
				$this->passwordId,
				$this->restServer->getMethod(),
			);
			$event->send();

			return $event->isGranted();
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return false;
		}
	}
}
