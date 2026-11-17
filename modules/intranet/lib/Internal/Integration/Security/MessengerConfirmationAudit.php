<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Security;

use Bitrix\Intranet\Internal\Entity\Otp\MessengerConfirmationState;
use Bitrix\Intranet\Public\Enum\Otp\MessengerConfirmationStatus;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Loader;

final class MessengerConfirmationAudit
{
	public const AUDIT_TYPE_REQUESTED = 'INTRANET_OTP_MESSENGER_REQUESTED';
	public const AUDIT_TYPE_CONFIRMED = 'INTRANET_OTP_MESSENGER_CONFIRMED';
	public const AUDIT_TYPE_DECLINED = 'INTRANET_OTP_MESSENGER_DECLINED';

	private const SEVERITY = 'SECURITY';
	private const ANALYTICS_TOOL = 'messenger_confirmation';
	private const ANALYTICS_CATEGORY = 'security';

	public function record(MessengerConfirmationState $state): void
	{
		$outcome = $this->resolveOutcome($state->status);
		if ($outcome === null)
		{
			return;
		}

		$this->write($state, ...$outcome);
	}

	private function write(MessengerConfirmationState $state, string $auditType, string $metricEvent): void
	{
		$this->runSilently(
			function () use ($state, $auditType): void {
				if (Loader::includeModule('security'))
				{
					\CSecurityEvent::getInstance()->doLog(
						self::SEVERITY,
						$auditType,
						(string)$state->userId,
						$this->describeAttempt($state),
					);
				}
			},
		);
		$this->runSilently(
			static fn() => (new AnalyticsEvent($metricEvent, self::ANALYTICS_TOOL, self::ANALYTICS_CATEGORY))->send(),
		);
	}

	private function describeAttempt(MessengerConfirmationState $state): string
	{
		$details = ['requestId' => $state->requestId] + ($state->attemptContext ?? []);
		$parts = [];

		foreach ($details as $name => $value)
		{
			if ($value !== '')
			{
				$parts[] = $name . '=' . $value;
			}
		}

		return implode('; ', $parts);
	}

	/**
	 * @return array{0: string, 1: string}|null
	 */
	private function resolveOutcome(MessengerConfirmationStatus $status): ?array
	{
		return match ($status)
		{
			MessengerConfirmationStatus::Pending => [self::AUDIT_TYPE_REQUESTED, 'messenger_confirm_requested'],
			MessengerConfirmationStatus::Confirmed => [self::AUDIT_TYPE_CONFIRMED, 'messenger_confirm_approved'],
			MessengerConfirmationStatus::Declined => [self::AUDIT_TYPE_DECLINED, 'messenger_confirm_declined'],
			default => null,
		};
	}

	private function runSilently(\Closure $operation): void
	{
		try
		{
			$operation();
		}
		catch (\Throwable)
		{
		}
	}
}
