<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Service;

use Bitrix\Main\Type\Date;
use Bitrix\Rest\Internal\Service\Application\Status\ApplicationHoldState;
use Bitrix\Rest\Internal\Service\Application\Status\MarketSubscriptionState;
use Bitrix\Rest\Internal\Service\Application\Status\ServerStatusTime;
use Bitrix\Rest\Public\Contract\Application\Status\ApplicationHoldStateInterface;
use Bitrix\Rest\Public\Contract\Application\Status\MarketSubscriptionStateInterface;
use Bitrix\Rest\Public\Contract\Application\Status\StatusTimeInterface;
use Bitrix\Rest\Public\Provider\Dto\ApplicationData;
use Bitrix\Rest\Public\Provider\Dto\ApplicationStatus;
use Bitrix\Rest\Public\Provider\Dto\ApplicationStatusData;

final class ApplicationStatusCalculator
{
	private const PAYMENT_NOTIFY_DAYS = 5;
	private const PAYMENT_GRACE_PERIOD_DAYS = -14;
	private const SECONDS_IN_DAY = 86400;

	public function __construct(
		private readonly MarketSubscriptionStateInterface $subscriptionState = new MarketSubscriptionState(),
		private readonly StatusTimeInterface $time = new ServerStatusTime(),
		private readonly ApplicationHoldStateInterface $holdState = new ApplicationHoldState(),
	)
	{
	}

	public function calculate(ApplicationData $application): ApplicationStatusData
	{
		$status = $application->getStatus() ?? ApplicationStatus::Unknown;
		$dateFinish = $application->getDateFinish();

		if ($status === ApplicationStatus::Subscription)
		{
			$state = $this->calculateSubscriptionState($status);
		}
		elseif ($dateFinish !== null && $status !== ApplicationStatus::Free)
		{
			$state = $this->calculateDeadlineState($application, $status, $dateFinish);
		}
		elseif ($status === ApplicationStatus::Demo)
		{
			$state = new ApplicationStatusData(
				status: $status,
				isPaymentAllowed: true,
				isPaymentExpired: false,
				isPaymentNotifyNeeded: true,
				daysLeft: null,
				isHeld: false,
			);
		}
		else
		{
			$state = new ApplicationStatusData(
				status: $status,
				isPaymentAllowed: true,
				isPaymentExpired: false,
				isPaymentNotifyNeeded: false,
				daysLeft: null,
				isHeld: false,
			);
		}

		return $this->applyHold($application, $state);
	}

	private function calculateSubscriptionState(ApplicationStatus $status): ApplicationStatusData
	{
		if (!$this->subscriptionState->isAvailable())
		{
			return new ApplicationStatusData(
				status: $status,
				isPaymentAllowed: false,
				isPaymentExpired: true,
				isPaymentNotifyNeeded: true,
				daysLeft: null,
				isHeld: false,
			);
		}

		$finalDate = $this->subscriptionState->getFinalDate();
		if ($finalDate === null)
		{
			return new ApplicationStatusData(
				status: $status,
				isPaymentAllowed: true,
				isPaymentExpired: false,
				isPaymentNotifyNeeded: false,
				daysLeft: null,
				isHeld: false,
			);
		}

		$daysLeft = $this->daysLeftUntil($finalDate);
		if ($daysLeft < 0)
		{
			return new ApplicationStatusData(
				status: $status,
				isPaymentAllowed: false,
				isPaymentExpired: true,
				isPaymentNotifyNeeded: true,
				daysLeft: $daysLeft,
				isHeld: false,
			);
		}

		return new ApplicationStatusData(
			status: $status,
			isPaymentAllowed: true,
			isPaymentExpired: false,
			isPaymentNotifyNeeded: $daysLeft < self::PAYMENT_NOTIFY_DAYS,
			daysLeft: $daysLeft,
			isHeld: false,
		);
	}

	private function calculateDeadlineState(
		ApplicationData $application,
		ApplicationStatus $status,
		Date $dateFinish,
	): ApplicationStatusData
	{
		$daysLeft = $this->daysLeftUntil($dateFinish);

		if ($daysLeft >= self::PAYMENT_NOTIFY_DAYS && $status !== ApplicationStatus::Trial)
		{
			return new ApplicationStatusData(
				status: $status,
				isPaymentAllowed: true,
				isPaymentExpired: false,
				isPaymentNotifyNeeded: false,
				daysLeft: $daysLeft,
				isHeld: false,
			);
		}

		if ($daysLeft >= 0)
		{
			return new ApplicationStatusData(
				status: $status,
				isPaymentAllowed: true,
				isPaymentExpired: false,
				isPaymentNotifyNeeded: true,
				daysLeft: $daysLeft,
				isHeld: false,
			);
		}

		if ($status === ApplicationStatus::Trial)
		{
			return new ApplicationStatusData(
				status: $status,
				isPaymentAllowed: false,
				isPaymentExpired: true,
				isPaymentNotifyNeeded: true,
				daysLeft: $daysLeft,
				isHeld: false,
			);
		}

		if ($status === ApplicationStatus::Paid && $daysLeft < self::PAYMENT_GRACE_PERIOD_DAYS)
		{
			return $this->calculateGraceExceededState($application, $status, $daysLeft);
		}

		return new ApplicationStatusData(
			status: $status,
			isPaymentAllowed: true,
			isPaymentExpired: true,
			isPaymentNotifyNeeded: true,
			daysLeft: $daysLeft,
			isHeld: false,
		);
	}

	private function calculateGraceExceededState(
		ApplicationData $application,
		ApplicationStatus $status,
		int $daysLeft,
	): ApplicationStatusData
	{
		$demoUrl = $application->getDemoUrl();
		if ($application->isTrialed() === false && $demoUrl !== null && $demoUrl !== '')
		{
			return new ApplicationStatusData(
				status: ApplicationStatus::Demo,
				isPaymentAllowed: true,
				isPaymentExpired: true,
				isPaymentNotifyNeeded: true,
				daysLeft: $daysLeft,
				isHeld: false,
			);
		}

		return new ApplicationStatusData(
			status: $status,
			isPaymentAllowed: false,
			isPaymentExpired: true,
			isPaymentNotifyNeeded: true,
			daysLeft: $daysLeft,
			isHeld: false,
		);
	}

	private function applyHold(ApplicationData $application, ApplicationStatusData $state): ApplicationStatusData
	{
		$clientId = $application->getClientId();

		if (empty($clientId) || !$this->holdState->isHeld($clientId))
		{
			return $state;
		}

		return new ApplicationStatusData(
			status: $state->getStatus(),
			isPaymentAllowed: $state->isPaymentAllowed(),
			isPaymentExpired: $state->isPaymentExpired(),
			isPaymentNotifyNeeded: true,
			daysLeft: $state->getDaysLeft(),
			isHeld: true,
		);
	}

	private function daysLeftUntil(Date $date): int
	{
		$diff = $date->getTimestamp() - $this->time->getTimezoneOffset() - $this->time->getCurrentTimestamp();

		return (int)floor($diff / self::SECONDS_IN_DAY);
	}
}
