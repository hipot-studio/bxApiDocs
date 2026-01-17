<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Factory\Otp;

use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Enum\Otp\PromoteMode;
use Bitrix\Intranet\Internal\Enum\Otp\OtpBannerType;
use Bitrix\Intranet\Internal\Integration\Security\OtpSettings;
use Bitrix\Intranet\Internal\Integration\Security\PersonalOtp;
use Bitrix\Intranet\Internal\Service\Otp\MobilePush;
use Bitrix\Main\Type\Date;

class BannerTypeFactory
{
	private bool $isAdmin;
	private User $user;
	private PersonalOtp $personalOtp;
	private OtpSettings $settingsOtp;
	private MobilePush $pushOtp;

	public function __construct()
	{
		$this->user = new User((int)CurrentUser::get()->getId());
		$this->isAdmin = CurrentUser::get()->isAdmin() ?? false;
		$this->personalOtp = new PersonalOtp($this->user);
		$this->settingsOtp = new OtpSettings();
		$this->pushOtp = MobilePush::createByDefault();
	}

	public function create(): ?OtpBannerType
	{
		if ($this->user->getId() <= 0)
		{
			return null;
		}

		$type = $this->getType();

		if ($type && $this->canShow($type))
		{
			return $type;
		}

		return null;
	}

	private function getType(): ?OtpBannerType
	{
		if (
			!$this->personalOtp->isActivated()
			&& $this->settingsOtp->isMandatoryUsing()
			&& !$this->personalOtp->canSkipMandatoryByRights()
		)
		{
			return OtpBannerType::MANDATORY_2FA;
		}

		if (
			$this->isActivatedOldTypeOtp()
			&& $this->pushOtp->gracePeriodEnabled()
		) {
			return OtpBannerType::ENABLED_OLD_2FA_AND_NEED_PUSH_2FA;
		}

		if ($this->pushOtp->getPromoteMode() !== PromoteMode::High)
		{
			if (
				!$this->personalOtp->isActivated()
				&& $this->user->isIntranet()
			)
			{
				return OtpBannerType::DISABLED_ALL_2FA;
			}

			if (
				$this->isAdmin
				&& $this->isActivatedPushTypeOtp()
				&& !$this->pushOtp->gracePeriodEnabled()
			) {
				return OtpBannerType::ONLY_ADMIN_ENABLED_NEW_2FA;
			}

			if (
				$this->isActivatedOldTypeOtp()
				&& !$this->pushOtp->gracePeriodEnabled()
			) {
				return OtpBannerType::ENABLED_OLD_2FA;
			}
		}

		return null;
	}

	private function isActivatedOldTypeOtp(): bool
	{
		return $this->personalOtp->isActivated() && !$this->personalOtp->isPushType();
	}

	private function isActivatedPushTypeOtp(): bool
	{
		return $this->personalOtp->isActivated() && $this->personalOtp->isPushType();
	}

	private function canShow(OtpBannerType $type): bool
	{
		$lastShow = \CUserOptions::GetOption('intranet', 'push_otp_popup_last_show', null);

		if (empty($lastShow))
		{
			return true;
		}

		$lastShowType = \CUserOptions::GetOption('intranet', 'push_otp_popup_last_type', null);
		$lastShowType = $lastShowType && is_int($lastShowType) ? OtpBannerType::tryFrom($lastShowType) : null;

		if (
			$lastShowType === $type
			&& $this->pushOtp->getPromoteMode() !== PromoteMode::High
			&& in_array($type, [
				OtpBannerType::ONLY_ADMIN_ENABLED_NEW_2FA,
				OtpBannerType::ENABLED_OLD_2FA,
				OtpBannerType::DISABLED_ALL_2FA,
			], true)
		)
		{
			return false;
		}

		$lastShowDate = new Date($lastShow, 'd.m.Y');
		$today = new Date();
		$interval = $today->getDiff($lastShowDate);
		$daysSinceLastShow = $interval->days;

		if ($this->pushOtp->isGracePeriodEnded() && !$this->personalOtp->canSkipMandatory())
		{
			return true;
		}

		if ($this->pushOtp->gracePeriodEnabled())
		{
			$gracePeriodStartDate = $this->pushOtp->getGracePeriodStart();
			$daysSinceGraceStart = $today->getDiff($gracePeriodStartDate)->days;

			$requiredDaysBetweenShows = $this->pushOtp->getShowFrequencyForDay($daysSinceGraceStart);

			return $daysSinceLastShow >= $requiredDaysBetweenShows;
		}

		return $daysSinceLastShow > 0;
	}
}
