<?php

namespace Bitrix\BIConnector\Internal\Services\Share;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardShare;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardShareRepository;
use Bitrix\Main\Type\DateTime;

class SharePasswordService
{
	private const MAX_LOGIN_ATTEMPTS = 5;
	private const LOGIN_LOCK_DURATION_MINUTES = 5;

	public function __construct(
		private readonly SupersetDashboardShareRepository $repository,
	)
	{
	}

	public function checkPassword(SupersetDashboardShare $share, string $password): bool
	{
		return $share->getPassword() === $password;
	}

	public function checkPasswordHash(SupersetDashboardShare $share, string $passwordHash): bool
	{
		return hash_equals(hash('sha256', $share->getPassword()), $passwordHash);
	}

	public function isLoginLocked(SupersetDashboardShare $share): bool
	{
		$lockedTill = $share->getLoginLockedTill();
		if ($lockedTill === null)
		{
			return false;
		}

		if ($lockedTill->getTimestamp() > time())
		{
			return true;
		}

		$this->resetLoginAttempts($share);

		return false;
	}

	public function getRemainingLockMinutes(SupersetDashboardShare $share): int
	{
		$lockedTill = $share->getLoginLockedTill();
		if ($lockedTill === null)
		{
			return 0;
		}

		$remaining = $lockedTill->getTimestamp() - time();

		return $remaining > 0 ? (int)ceil($remaining / 60) : 0;
	}

	public function registerFailedLoginAttempt(SupersetDashboardShare $share): void
	{
		$attempts = $share->getLoginAttempts() + 1;
		$share->setLoginAttempts($attempts);

		if ($attempts >= self::MAX_LOGIN_ATTEMPTS)
		{
			$lockedTill = (new DateTime())->add('+' . self::LOGIN_LOCK_DURATION_MINUTES . ' minutes');
			$share->setLoginLockedTill($lockedTill);
		}

		$this->repository->save($share);
	}

	public function resetLoginAttempts(SupersetDashboardShare $share): void
	{
		$share
			->setLoginAttempts(0)
			->setLoginLockedTill(null)
		;
		$this->repository->save($share);
	}
}
