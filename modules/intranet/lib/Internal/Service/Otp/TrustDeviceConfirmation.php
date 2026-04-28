<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Integration\Main\VerifyPhoneService;
use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\Date;
use Bitrix\Intranet\Internal\Integration\Security\PersonalOtp;

class TrustDeviceConfirmation
{
	private PersonalOtp $personalOtpSettings;
	private const TRUST_DEVICE_CONFIRMATION_PERIOD = 180;
	private int $userId;

	public function __construct(PersonalOtp $personalOtpSettings)
	{
		$this->userId = $personalOtpSettings->getOtpInfo()->userId;
		$this->personalOtpSettings = $personalOtpSettings;
	}

	public function shouldShowConfirmation(): bool
	{
		$lastConfirmed = $this->getLastTrustDeviceConfirmationDate();
		if ($lastConfirmed === null)
		{
			$initialDateTime = $this->personalOtpSettings->getInitialDate();
			if ($initialDateTime === null)
			{
				return false;
			}

			$initialDate = Date::createFromTimestamp($initialDateTime->getTimestamp());
			$now = new Date();
			$diff = $now > $initialDate ? $now->getDiff($initialDate)->days : 0;
			$requiredShowStartDate = $this->getRequiredShowStartDate();

			if ($requiredShowStartDate !== null && $now < $requiredShowStartDate)
			{
				return false;
			}

			return $diff > self::TRUST_DEVICE_CONFIRMATION_PERIOD || !$this->personalOtpSettings->isActivated();
		}

		$now = new Date();
		$diff = $now > $lastConfirmed ? $now->getDiff($lastConfirmed)->days : 0;

		return $diff > self::TRUST_DEVICE_CONFIRMATION_PERIOD;
	}

	public function getConfirmationData(): array
	{
		$deviceInfo = (new PersonalMobilePush($this->personalOtpSettings))->getDeviceInfo();

		return [
			'settingsPath' => SITE_DIR . 'company/personal/user/' . $this->userId . '/common_security/?page=otpConnected',
			'device' => $deviceInfo['displayModel'] ?? '',
			'platform' => in_array(strtolower($deviceInfo['platform'] ?? ''), ['android', 'ios']) ? strtolower($deviceInfo['platform']) : 'unknown',
			...$this->personalOtpSettings->getOtpConfig(),
			'isDeactivated' => !$this->personalOtpSettings->isActivated(),
			'canSendSms' => (new VerifyPhoneService(new User($this->userId)))->canSendSms(),
		];
	}

	public function resetLastTrustDeviceConfirmationDate(): void
	{
		\CUserOptions::DeleteOption('intranet', 'otp_device_last_confirmation_date', false, $this->userId);
	}

	public function onDeactivateOtp(): void
	{
		$this->resetLastTrustDeviceConfirmationDate();
		\CUserOptions::SetOption('intranet', 'require_show_device_confirmation_date', strtotime('+1 day'), false, $this->userId);
	}

	private function getRequiredShowStartDate(): ?Date
	{
		$requirementDate = (int)\CUserOptions::GetOption('intranet', 'require_show_device_confirmation_date', 0, $this->userId);

		if ($requirementDate > 0)
		{
			return Date::createFromTimestamp($requirementDate);
		}

		return null;
	}

	private function getLastTrustDeviceConfirmationDate(): ?Date
	{
		$lastConfirmationDate = \CUserOptions::GetOption('intranet', 'otp_device_last_confirmation_date', null, $this->userId);

		if (!empty($lastConfirmationDate))
		{
			try
			{
				return new Date($lastConfirmationDate, 'd.m.Y');
			}
			catch (ObjectException)
			{
				return null;
			}
		}

		return null;
	}
}
