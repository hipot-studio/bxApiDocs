<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Repository;

class BackupEmailConfirmationRepository
{
	private const MODULE_ID = 'intranet';
	private const PENDING_EMAIL_OPTION = 'otp_pending_auth_email';

	public function save(int $userId, string $email): void
	{
		\CUserOptions::SetOption(
			self::MODULE_ID,
			self::PENDING_EMAIL_OPTION,
			$email,
			false,
			$userId,
		);
	}

	public function getByUserId(int $userId): ?string
	{
		$email = \CUserOptions::GetOption(
			self::MODULE_ID,
			self::PENDING_EMAIL_OPTION,
			'',
			$userId,
		);

		return $email !== '' ? $email : null;
	}

	public function clear(int $userId): void
	{
		\CUserOptions::DeleteOption(
			self::MODULE_ID,
			self::PENDING_EMAIL_OPTION,
			false,
			$userId,
		);
	}
}
