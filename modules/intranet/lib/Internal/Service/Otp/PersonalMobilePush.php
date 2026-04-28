<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Integration\Security\PersonalOtp;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Date;
use Bitrix\Pull\Event;
use Bitrix\Security\Mfa\OtpException;
use Bitrix\Security\Mfa\OtpType;

class PersonalMobilePush
{
	public const PHONE_NUMBER_CONFIRMED_EVENT_NAME = 'onPhoneNumberConfirmed';

	/**
	 * @throws LoaderException
	 * @throws SystemException
	 */
	public function __construct(
		private readonly PersonalOtp $personalOtp,
	) {
		if (!Loader::includeModule('security'))
		{
			throw new SystemException('Module security is not installed');
		}
	}

	/**
	 * @throws LoaderException
	 * @throws ArgumentTypeException
	 * @throws ArgumentOutOfRangeException
	 * @throws SystemException
	 */
	public static function createByUser(User $user): self
	{
		return new self(new PersonalOtp($user));
	}

	public function isActivated(): bool
	{
		return $this->personalOtp->isActivated() && $this->personalOtp->getType() === OtpType::Push;
	}

	/**
	 * @throws OtpException|ArgumentOutOfRangeException|ArgumentTypeException
	 */
	public function setup(string $secret, string $totpCode, array $initParams = []): void
	{
		$this->personalOtp->setup($secret, $totpCode, OtpType::Push, $initParams);
		(new DeviceReconnect())->clearLost($this->personalOtp->getOtpInfo()->userId);
	}

	public function getDeviceInfo(): array
	{
		return (array)($this->personalOtp->getInitParams()['deviceInfo'] ?? []);
	}

	public function requirePhoneConfirmation(): void
	{
		if ($this->isActivated())
		{
			$userId = $this->personalOtp->getOtpInfo()->userId;
			\CUserOptions::SetOption('intranet', 'require_phone_confirmation', time(), false, $userId);
			(new TrustPhoneNumberConfirmation($this, $userId))->resetLastTrustPhoneNumberConfirmationDate();
		}
	}

	public function clearPhoneConfirmationRequirement(): void
	{
		$userId = $this->personalOtp->getOtpInfo()->userId;
		$isPhoneConfirmationRequired = $this->isPhoneConfirmationRequired();

		if (!$isPhoneConfirmationRequired)
		{
			return;
		}

		\CUserOptions::DeleteOption('intranet', 'require_phone_confirmation', false, $userId);

		if (Loader::includeModule('pull'))
		{
			Event::add(
				[$userId],
				[
					'module_id' => 'intranet',
					'command' => self::PHONE_NUMBER_CONFIRMED_EVENT_NAME,
				],
			);
		}
	}

	public function isPhoneConfirmationRequired(): bool
	{
		if (!$this->isActivated())
		{
			return false;
		}

		$userId = $this->personalOtp->getOtpInfo()->userId;

		return \CUserOptions::GetOption('intranet', 'require_phone_confirmation', 'N', $userId) !== 'N';
	}

	public function getPhoneConfirmationRequiredStartDate(): ?Date
	{
		$userId = $this->personalOtp->getOtpInfo()->userId;
		$requirementDate = (int)\CUserOptions::GetOption('intranet', 'require_phone_confirmation', 'N', $userId);

		if ($requirementDate > 0)
		{
			return Date::createFromTimestamp($requirementDate);
		}

		return null;
	}

	public static function isPhoneConfirmationRequiredByUser(User $user): bool
	{
		return \CUserOptions::GetOption('intranet', 'require_phone_confirmation', 'N', $user->getId()) !== 'N';
	}
}
