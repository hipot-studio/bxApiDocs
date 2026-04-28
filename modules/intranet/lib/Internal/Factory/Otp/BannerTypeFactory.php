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
use Bitrix\Intranet\Internal\Service\Otp\PersonalMobilePush;
use Bitrix\Intranet\Internal\Service\Otp\TrustDeviceConfirmation;
use Bitrix\Intranet\Internal\Service\Otp\TrustPhoneNumberConfirmation;
use Bitrix\Intranet\Internal\Service\Otp\DeviceReconnect;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\Date;

class BannerTypeFactory
{
	private bool $isAdmin;
	private User $user;
	private ?PersonalOtp $personalOtp = null;
	private OtpSettings $settingsOtp;
	private MobilePush $pushOtp;

	public function __construct()
	{
		$this->user = new User((int)CurrentUser::get()->getId());
		$this->isAdmin = CurrentUser::get()->isAdmin() ?? false;
		$this->settingsOtp = new OtpSettings();
		$this->pushOtp = MobilePush::createByDefault();
	}

	public function create(): ?OtpBannerType
	{
		if ($this->user->getId() <= 0)
		{
			return null;
		}

		$deviceReconnect = new DeviceReconnect();
		if ($deviceReconnect->shouldShowReconnect() && $this->getPersonalOtp()?->isActivated())
		{
			return OtpBannerType::RECONNECT_TRUSTED_DEVICE;
		}

		$type = $this->getType();

		if ($type && $this->canShow($type))
		{
			return $type;
		}

		if ($this->getPersonalOtp()?->isPushType())
		{
			$type = (new TrustDeviceConfirmation($this->getPersonalOtp()))->shouldShowConfirmation() ? OtpBannerType::TRUST_DEVICE_CONFIRMATION : null;

			if (!$type)
			{
				$personalMobilePush = new PersonalMobilePush($this->getPersonalOtp());
				$trustPhoneNumberConfirmation = new TrustPhoneNumberConfirmation($personalMobilePush);

				return $trustPhoneNumberConfirmation->shouldShowConfirmation()
					? OtpBannerType::TRUST_PHONE_NUMBER_CONFIRMATION
					: null;
			}

			return $type;
		}

		return null;
	}

	public static function onLicenseChanged(): void
	{
		Option::set('intranet', 'otp_banner_license_delay', time() + 86400 * 7);
	}

	private function getType(): ?OtpBannerType
	{
		if (
			!$this->getPersonalOtp()?->isActivated()
			&& $this->settingsOtp->isMandatoryUsing()
			&& !$this->getPersonalOtp()?->canSkipMandatoryByRights()
		) {
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
				!$this->getPersonalOtp()?->isActivated()
				&& $this->user->isIntranet()
			) {
				return OtpBannerType::DISABLED_ALL_2FA;
			}

			if (
				$this->isAdmin
				&& $this->isActivatedPushTypeOtp()
				&& !$this->pushOtp->isDefault()
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
		return $this->getPersonalOtp()?->isActivated() && !$this->getPersonalOtp()?->isPushType();
	}

	private function isActivatedPushTypeOtp(): bool
	{
		return $this->getPersonalOtp()?->isActivated() && $this->getPersonalOtp()?->isPushType();
	}

	private function canShow(OtpBannerType $type): bool
	{
		if (!$this->canShowByLicense($type))
		{
			return false;
		}

		$lastShow = \CUserOptions::GetOption('intranet', 'push_otp_popup_last_show', null);

		if (empty($lastShow) || !is_string($lastShow))
		{
			return true;
		}

		$lastShowType = \CUserOptions::GetOption('intranet', 'push_otp_popup_last_show_type', null);
		$lastShowType = $lastShowType && is_int($lastShowType) ? OtpBannerType::tryFrom($lastShowType) : null;

		if (
			$lastShowType === $type
			&& $this->pushOtp->getPromoteMode() !== PromoteMode::High
			&& in_array($type, [
				OtpBannerType::ONLY_ADMIN_ENABLED_NEW_2FA,
				OtpBannerType::ENABLED_OLD_2FA,
				OtpBannerType::DISABLED_ALL_2FA,
			], true)
		) {
			return false;
		}

		$lastShowDate = new Date($lastShow, 'd.m.Y');
		$today = new Date();
		$interval = $today->getDiff($lastShowDate);
		$daysSinceLastShow = $interval->days;

		if ($this->pushOtp->isGracePeriodEnded() && !$this->getPersonalOtp()?->canSkipMandatory())
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

	private function getPersonalOtp(): ?PersonalOtp
	{
		if ($this->personalOtp === null)
		{
			$this->personalOtp = $this->settingsOtp->getPersonalSettingsByUserId($this->user->getId());
		}

		return $this->personalOtp;
	}

	private function canShowByLicense(OtpBannerType $type): bool
	{
		if (!in_array($type, [
			OtpBannerType::ONLY_ADMIN_ENABLED_NEW_2FA,
			OtpBannerType::ENABLED_OLD_2FA,
			OtpBannerType::DISABLED_ALL_2FA,
		], true))
		{
			return true;
		}

		if (Loader::includeModule('bitrix24') && (\CBitrix24::isLicensePaid() || \CBitrix24::IsNfrLicense()))
		{
			$delayByLicense = (int)Option::get('intranet', 'otp_banner_license_delay', 0);

			if ($delayByLicense > 0)
			{
				return $delayByLicense < time();
			}

			return true;
		}

		if (Application::getInstance()->getLicense()->isTimeBound())
		{
			return true;
		}

		return false;
	}
}
