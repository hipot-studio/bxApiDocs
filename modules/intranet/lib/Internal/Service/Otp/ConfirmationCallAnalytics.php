<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Intranet\Internal\Enum\Otp\PhoneConfirmationScenario;
use Bitrix\Main\Analytics\AnalyticsEvent;

/**
 * Subject outcomes of the confirmation call for the existing product analytics.
 *
 * Only a scenario, a whitelisted failure category and a confirmation delay leave this class: the
 * phone, the provider number, the attempt id and any native code never reach an event.
 */
final class ConfirmationCallAnalytics
{
	public const EVENT_STARTED = 'confirmation_call_started';
	public const EVENT_CONFIRMED = 'confirmation_call_confirmed';
	public const EVENT_FAILED = 'confirmation_call_failed';
	public const EVENT_SMS_SWITCH = 'confirmation_call_sms_switch';

	public const REASON_OTHER = 'other';
	public const REASON_FORBIDDEN = 'forbidden';

	private const TOOL = 'otp';
	private const CATEGORY = 'security';

	private const REASONS = [
		ConfirmationCallCoordinator::ERROR_UNAVAILABLE => 'unavailable',
		ConfirmationCallCoordinator::ERROR_INVALID_REQUEST => 'invalidRequest',
		ConfirmationCallCoordinator::ERROR_EXPIRED => 'expired',
		ConfirmationCallCoordinator::ERROR_STALE_ATTEMPT => 'staleAttempt',
		ConfirmationCallCoordinator::ERROR_COMPLETION_FAILED => 'completionFailed',
		ConfirmationCallCoordinator::ERROR_TOO_MANY_ATTEMPTS => 'tooManyAttempts',
		self::REASON_FORBIDDEN => self::REASON_FORBIDDEN,
	];

	private \Closure $sender;

	public function __construct(?\Closure $sender = null)
	{
		$this->sender = $sender ?? static function (AnalyticsEvent $event): void
		{
			$event->send();
		};
	}

	public function started(PhoneConfirmationScenario $scenario): void
	{
		$this->dispatch(self::EVENT_STARTED, $scenario, AnalyticsEvent::STATUS_SUCCESS);
	}

	public function confirmed(PhoneConfirmationScenario $scenario, int $delaySeconds): void
	{
		$this->dispatch(
			self::EVENT_CONFIRMED,
			$scenario,
			AnalyticsEvent::STATUS_SUCCESS,
			null,
			max(0, $delaySeconds),
		);
	}

	public function failed(PhoneConfirmationScenario $scenario, string $errorCode): void
	{
		$this->dispatch(
			self::EVENT_FAILED,
			$scenario,
			AnalyticsEvent::STATUS_ERROR,
			self::REASONS[$errorCode] ?? self::REASON_OTHER,
		);
	}

	public function switchedToSms(PhoneConfirmationScenario $scenario): void
	{
		$this->dispatch(self::EVENT_SMS_SWITCH, $scenario, AnalyticsEvent::STATUS_SUCCESS);
	}

	/**
	 * Analytics is an observation, never a reason to break the flow it observes, so any failure of
	 * the underlying tool stays here.
	 */
	private function dispatch(
		string $event,
		PhoneConfirmationScenario $scenario,
		string $status,
		?string $reason = null,
		?int $delaySeconds = null,
	): void
	{
		try
		{
			$analyticsEvent = (new AnalyticsEvent($event, self::TOOL, self::CATEGORY))
				->setType($scenario->value)
				->setStatus($status)
			;

			if ($reason !== null)
			{
				$analyticsEvent->setP1('reason_' . $reason);
			}

			if ($delaySeconds !== null)
			{
				$analyticsEvent->setP2('delay_' . self::describeDelay($delaySeconds));
			}

			($this->sender)($analyticsEvent);
		}
		catch (\Throwable)
		{
		}
	}

	/**
	 * The delay is reported as a distribution: a per-second value would turn one measurement into
	 * hundreds of distinct ones and make the report unreadable.
	 */
	private static function describeDelay(int $delaySeconds): string
	{
		return match (true)
		{
			$delaySeconds < 5 => '0-5',
			$delaySeconds < 15 => '5-15',
			$delaySeconds < 30 => '15-30',
			default => '30-plus',
		};
	}
}
