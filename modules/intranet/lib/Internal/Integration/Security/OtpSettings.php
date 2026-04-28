<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Integration\Security;

use Bitrix\Intranet\Repository\UserRepository;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\SystemException;
use Bitrix\Security\Mfa\Otp;
use Bitrix\Security\Mfa\OtpType;

class OtpSettings
{
	/** @var array<int, PersonalOtp> */
	private static array $userOtpData = [];
	private bool $isAvailable;

	/**
	 * @throws LoaderException
	 */
	public function __construct()
	{
		$this->isAvailable = Loader::includeModule('security');
	}

	public function isAvailable(): bool
	{
		return $this->isAvailable;
	}

	public function setDefaultType(OtpType $otpType): void
	{
		if (!$this->isAvailable)
		{
			return;
		}

		Otp::setDefaultType($otpType);
	}

	public function isEnabled(): bool
	{
		if (!$this->isAvailable)
		{
			return false;
		}

		return Otp::isOtpEnabled();
	}

	public function isMandatoryUsing(): bool
	{
		if (!$this->isAvailable)
		{
			return false;
		}

		$isMandatory = Otp::isMandatoryUsing();

		if ($isMandatory && Loader::includeModule('bitrix24'))
		{
			$otpRights = $this->getMandatoryRights();
			$adminGroup = 'G1';
			$employeeGroup = 'G' . \CBitrix24::getEmployeeGroupId();

			if (!in_array($adminGroup, $otpRights, true) || !in_array($employeeGroup, $otpRights, true))
			{
				$isMandatory = false;
			}
		}

		return $isMandatory;
	}

	public function getSkipMandatoryDays(): int
	{
		if (!$this->isAvailable)
		{
			return 0;
		}

		return Otp::getSkipMandatoryDays();
	}

	public function isRecoveredCodesEnabled(): bool
	{
		if (!$this->isAvailable)
		{
			return false;
		}

		return Otp::isRecoveryCodesEnabled();
	}

	public function getDefaultType(): ?OtpType
	{
		if (!$this->isAvailable)
		{
			return null;
		}

		return Otp::getDefaultType();
	}

	public function isDefaultTypePush(): bool
	{
		if (!$this->isAvailable)
		{
			return false;
		}

		return $this->getDefaultType() === OtpType::Push;
	}

	public function getDeferredParams(): ?array
	{
		if (!$this->isAvailable)
		{
			return null;
		}

		return Otp::getDeferredParams();
	}

	public function getMandatoryRights(): array
	{
		if (!$this->isAvailable)
		{
			return [];
		}

		return Otp::getMandatoryRights();
	}

	public function setSkipMandatoryDays(int $days): void
	{
		if (!$this->isAvailable)
		{
			return;
		}

		Otp::setSkipMandatoryDays($days);
	}

	public function setMandatoryUsing(bool $isMandatory): void
	{
		if (!$this->isAvailable)
		{
			return;
		}

		if (Loader::includeModule('bitrix24'))
		{
			$otpRights = $this->getMandatoryRights();
			$employeeGroup = 'G' . \CBitrix24::getEmployeeGroupId();
			$adminGroup = 'G1';

			if ($isMandatory)
			{
				Otp::setMandatoryUsing();
				if (!in_array($adminGroup, $otpRights, true))
				{
					$otpRights[] = $adminGroup;
				}

				if (!in_array($employeeGroup, $otpRights, true))
				{
					$otpRights[] = $employeeGroup;
				}
			}
			else
			{
				foreach ($otpRights as $key => $group)
				{
					if ($group === $adminGroup || $group === $employeeGroup)
					{
						unset($otpRights[$key]);
					}
				}
			}

			Otp::setMandatoryRights($otpRights);
			$event = $isMandatory ? '2fa_on_portal' : '2fa_off_portal';
			$analyticEvent = new AnalyticsEvent($event, 'user_settings', 'security');
			$analyticEvent->send();
		}
		else
		{
			Otp::setMandatoryUsing($isMandatory);
		}
	}

	/**
	 * @throws ArgumentTypeException
	 * @throws LoaderException
	 * @throws ArgumentOutOfRangeException
	 * @throws SystemException
	 */
	public function getPersonalSettingsByUserId(int $userId): ?PersonalOtp
	{
		if (!$this->isAvailable)
		{
			return null;
		}

		if (isset(self::$userOtpData[$userId]))
		{
			return self::$userOtpData[$userId];
		}

		$user = (new UserRepository())->getUserById($userId);

		self::$userOtpData[$userId] = $user ? new PersonalOtp($user) : null;

		return self::$userOtpData[$userId];
	}
}
