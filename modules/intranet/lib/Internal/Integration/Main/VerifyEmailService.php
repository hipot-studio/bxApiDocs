<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Main;

use Bitrix\Intranet\Entity\Type\Email;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Integration\Security\PersonalOtp;
use Bitrix\Intranet\Internal\Repository\BackupEmailConfirmationRepository;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Authentication\Context;
use Bitrix\Main\Authentication\Internal\UserAuthCodeTable;
use Bitrix\Main\Authentication\ShortCode;
use Bitrix\Main\Context as HttpContext;
use Bitrix\Main\Error;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Mail\Event;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Security\Mfa\Otp;

class VerifyEmailService
{

	public function __construct(
		private readonly User $user,
	) {
	}

	public function canSendEmail(): bool
	{
		return Otp::isEmailEnabled();
	}

	/**
	 * @throws LoaderException
	 * @throws ArgumentTypeException
	 * @throws ArgumentOutOfRangeException
	 * @throws SystemException
	 */
	public function canLoginByEmail(): bool
	{
		return $this->canSendEmail()
			&& !empty((new PersonalOtp($this->user))->getBackupEmail());
	}
}
