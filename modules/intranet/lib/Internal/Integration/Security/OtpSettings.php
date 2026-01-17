<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Integration\Security;

use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\SystemException;
use Bitrix\Security\Mfa\Otp;
use Bitrix\Security\Mfa\OtpType;
use Bitrix\Intranet\Entity;

class OtpSettings
{
	/** @var array<int, PersonalOtp> */
	private array $userOtpData = [];

	/**
	 * @throws LoaderException
	 * @throws SystemException
	 */
	public function __construct()
	{
		if (!Loader::includeModule('security'))
		{
			throw new SystemException('Module security is not installed');
		}
	}

	public function setDefaultType(OtpType $otpType): void
	{
		Otp::setDefaultType($otpType);
	}

	public function isEnabled(): bool
	{
		return Otp::isOtpEnabled();
	}

	public function isMandatoryUsing(): bool
	{
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
		return Otp::getSkipMandatoryDays();
	}

	public function isRecoveredCodesEnabled(): bool
	{
		return Otp::isRecoveryCodesEnabled();
	}

	public function getDefaultType(): OtpType
	{
		return Otp::getDefaultType();
	}

	public function isDefaultTypePush(): bool
	{
		return $this->getDefaultType() === OtpType::Push;
	}

	public function getDeferredParams(): ?array
	{
		return Otp::getDeferredParams();
	}

	public function getMandatoryRights(): array
	{
		return Otp::getMandatoryRights();
	}

	public function setSkipMandatoryDays(int $days): void
	{
		Otp::setSkipMandatoryDays($days);
	}

	public function setMandatoryUsing(bool $isMandatory): void
	{
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
	public function getPersonalSettingsByUserId(int $userId): PersonalOtp
	{
		if (isset($this->userOtpData[$userId]))
		{
			return $this->userOtpData[$userId];
		}

		$user = new Entity\User(
			id: $userId,
		);

		$this->userOtpData[$userId] = new PersonalOtp($user);

		return $this->userOtpData[$userId];
	}
}
