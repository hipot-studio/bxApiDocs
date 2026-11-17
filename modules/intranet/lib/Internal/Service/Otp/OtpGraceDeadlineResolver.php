<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Intranet\Entity\UserOtp;
use Bitrix\Intranet\Internal\Enum\Otp\PromoteMode;
use Bitrix\Intranet\Internal\Enum\StepperStatus;
use Bitrix\Main\Type\DateTime;
use Bitrix\Security\Mfa\OtpType;

/**
 * Deadline of an unconfigured mandatory 2FA: the moment the access is actually closed.
 * Callers apply it only to users for whom 2FA is mandatory.
 */
class OtpGraceDeadlineResolver
{
	private MobilePush $mobilePush;
	private ?bool $isSwitcherAhead = null;
	/** @var array<int, int>|null */
	private ?array $legacyOtpAllowedUserIds = null;

	public function __construct(?MobilePush $mobilePush = null)
	{
		$this->mobilePush = $mobilePush ?? MobilePush::createByDefault();
	}

	/**
	 * Returns null when there is no deadline, including configured 2FA.
	 */
	public function resolve(UserOtp $otpInfo): ?DateTime
	{
		$personalDeadline = $otpInfo->dateDeactivate;

		if ($otpInfo->isInitialized || $personalDeadline === null)
		{
			return null;
		}

		if (!$this->isSwitchedToPushOnCommonDate($otpInfo))
		{
			return $personalDeadline;
		}

		$commonDeadline = $this->mobilePush->getGracePeriod();

		return $commonDeadline < $personalDeadline->getTimestamp()
			? DateTime::createFromTimestamp($commonDeadline)
			: $personalDeadline
		;
	}

	/**
	 * HighPromotePushOtpSwitcher resets the personal deferral of such a record on the common date.
	 */
	private function isSwitchedToPushOnCommonDate(UserOtp $otpInfo): bool
	{
		return $this->isSwitcherAhead()
			&& $otpInfo->type !== OtpType::Push
			&& !$this->isLegacyOtpAllowed($otpInfo->userId)
		;
	}

	private function isSwitcherAhead(): bool
	{
		$this->isSwitcherAhead ??= $this->mobilePush->getPromoteMode() === PromoteMode::High
			&& $this->isSwitcherPending()
			&& $this->mobilePush->hasGracePeriod()
		;

		return $this->isSwitcherAhead;
	}

	private function isSwitcherPending(): bool
	{
		$status = (new HighPromotePushOtp($this->mobilePush))->getStepperStatus();

		return in_array($status, [StepperStatus::Scheduled, StepperStatus::Running], true);
	}

	/**
	 * The same set HighPromotePushOtpSwitcher skips, loaded once per resolver.
	 */
	private function isLegacyOtpAllowed(int $userId): bool
	{
		$this->legacyOtpAllowedUserIds ??= array_flip($this->mobilePush->getLegacyOtpAllowedUserIds());

		return isset($this->legacyOtpAllowedUserIds[$userId]);
	}
}
