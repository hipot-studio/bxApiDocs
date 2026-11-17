<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Integration\Main;

use Bitrix\Intranet\Entity\Type\Phone;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Exception\UpdateFailedException;
use Bitrix\Intranet\Internal\Exception\SendException;
use Bitrix\Intranet\Repository\UserRepository;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Security\Mfa\Otp;

class VerifyPhoneService
{
	public const ERROR_PHONE_REQUIRED_BY_PORTAL = 'CHANNEL_REMOVAL_PHONE_REQUIRED_BY_PORTAL';

	private PhoneAuthAdapter $phoneAuth;

	public function __construct(
		private readonly User $user,
	) {
		$this->phoneAuth = new PhoneAuthAdapter();
	}

	public static function createFor2Fa(User $user): self
	{
		return new self($user);
	}

	/**
	 * @throws SendException
	 */
	public function sendCodeByTemplate(string $template): array
	{
		$phone = new Phone($this->user->getAuthPhoneNumber() ?? '');

		return $this->phoneAuth->sendCode($phone, $template);
	}

	/**
	 * @throws UpdateFailedException
	 * @throws ArgumentException
	 */
	public function changeAuthPhone(Phone $phone): void
	{
		if (!$phone->isValid()) {
			throw new ArgumentException('Invalid phone number format');
		}

		$userRepository = new UserRepository();
		$existingUser = $userRepository->findUsersByLoginsAndPhoneNumbers([$phone->defaultFormat()]);

		if (!$existingUser->empty())
		{
			$userId = $existingUser->first()?->getId();

			if ($userId !== $this->user->getId())
			{
				$error = new Error(Loc::getMessage('INTRANET_INTERNAL_INTEGRATION_MAIN_VERIFY_PHONE_SERVICE_PHONE_IN_USE'));
				$errorCollection = new ErrorCollection([$error]);

				throw new UpdateFailedException($errorCollection);
			}
		}

		$this->user->setPhoneNumber($phone->defaultFormat());
		(new UserRepository())->update($this->user);
	}

	public function canRemoveAuthPhone(): bool
	{
		return Option::get('main', 'new_user_phone_required', 'N') !== 'Y';
	}

	public function getAuthPhoneRemovalUnavailableReason(): ?string
	{
		if ($this->canRemoveAuthPhone())
		{
			return null;
		}

		Loc::loadMessages(__FILE__);

		return Loc::getMessage('INTRANET_INTERNAL_INTEGRATION_MAIN_VERIFY_PHONE_SERVICE_PHONE_REQUIRED_BY_PORTAL');
	}

	/**
	 * @throws UpdateFailedException
	 */
	public function removeAuthPhone(): void
	{
		if (!$this->canRemoveAuthPhone())
		{
			$error = new Error(
				$this->getAuthPhoneRemovalUnavailableReason(),
				self::ERROR_PHONE_REQUIRED_BY_PORTAL,
			);

			throw new UpdateFailedException(new ErrorCollection([$error]));
		}

		// an empty string, not null: the core drops the phone auth row by isset() on the field
		$this->user->setPhoneNumber('');
		(new UserRepository())->update($this->user);
	}

	/**
	 * @throws ArgumentTypeException
	 * @throws SendException
	 */
	public function confirmPhoneNumber(string $code): bool
	{
		return $this->phoneAuth->confirm($this->user, $code);
	}

	public function isConfirmed(Phone $phone): bool
	{
		return (new UserRepository())->isConfirmedAuthPhone($this->user->getId(), $phone->defaultFormat());
	}

	public function canSendSms(): bool
	{
		if (!$this->user->isIntranet() || !Otp::isSmsEnabled())
		{
			return false;
		}

		return (IsModuleInstalled('bitrix24') && IsModuleInstalled('messageservice'))
			|| (
				Loader::includeModule('messageservice')
				&& \Bitrix\MessageService\Sender\SmsManager::getUsableSender()
			);
	}

	public function canLoginBySms(): bool
	{
		return $this->canSendSms()
			&& !empty($this->user->getAuthPhoneNumber())
			&& $this->isConfirmed(new Phone($this->user->getAuthPhoneNumber()));
	}
}
