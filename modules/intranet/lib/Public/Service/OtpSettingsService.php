<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Public\Service;

use Bitrix\Intranet\Internal\Enum\Otp\PromoteMode;
use Bitrix\Intranet\Internal\Integration\Main\VerifyEmailService;
use Bitrix\Intranet\Internal\Integration\Main\VerifyPhoneService;
use Bitrix\Intranet\Internal\Integration\Security\OtpSettings;
use Bitrix\Intranet\Internal\Service\Otp\MessengerConfirmationAvailabilityPolicy;
use Bitrix\Intranet\Internal\Service\Otp\MobilePush;
use Bitrix\Intranet\Repository\UserRepository;
use Bitrix\Intranet\Service\IntranetOption;

class OtpSettingsService
{
	public const OPTION_MESSENGER_CONFIRM_MIN_PROTOCOL_VERSION = 'messenger_confirmation_min_protocol_version';
	public const DEFAULT_MESSENGER_CONFIRM_MIN_PROTOCOL_VERSION = 62;

	private readonly MessengerConfirmationAvailabilityPolicy $messengerConfirmation;

	public function __construct(
		private readonly OtpSettings $otp,
		?MessengerConfirmationAvailabilityPolicy $messengerConfirmation = null,
	)
	{
		$this->messengerConfirmation = $messengerConfirmation ?? new MessengerConfirmationAvailabilityPolicy();
	}

	public function isEnabled(): bool
	{
		return $this->otp->isEnabled();
	}

	public function isEnabledForUser(int $userId): bool
	{
		return $this->otp->getPersonalSettingsByUserId($userId)?->isActivated() ?? false;
	}

	public function isMandatory(): bool
	{
		return $this->otp->isMandatoryUsing();
	}

	public function isPushOtpHighPromote(): bool
	{
		return $this->otp->isDefaultTypePush() && MobilePush::createByDefault()->getPromoteMode() === PromoteMode::High;
	}

	public function canLoginBySms(int $userId): bool
	{
		$user = (new UserRepository())->getUserById($userId);

		if ($user)
		{
			return (new VerifyPhoneService($user))->canLoginBySms();
		}

		return false;
	}

	public function canLoginByEmail(int $userId): bool
	{
		$user = (new UserRepository())->getUserById($userId);

		if ($user)
		{
			return (new VerifyEmailService($user))->canLoginByEmail();
		}

		return false;
	}

	public function isRecoveryCodesEnabled(): bool
	{
		return (new OtpSettings())->isRecoveredCodesEnabled();
	}

	/** A surface without its own protocol version (the portal login form) passes null. */
	public function isMessengerConfirmOffered(?int $clientProtocolVersion = null): bool
	{
		if (!$this->messengerConfirmation->isEnabled())
		{
			return false;
		}

		return $clientProtocolVersion === null
			|| $clientProtocolVersion >= $this->getMessengerConfirmMinProtocolVersion()
		;
	}

	public function canUseMessengerConfirm(int $userId): bool
	{
		return $this->messengerConfirmation->isAvailable($userId);
	}

	public function getMessengerConfirmMinProtocolVersion(): int
	{
		return (new IntranetOption())->getPositive(
			self::OPTION_MESSENGER_CONFIRM_MIN_PROTOCOL_VERSION,
			self::DEFAULT_MESSENGER_CONFIRM_MIN_PROTOCOL_VERSION,
		);
	}
}
