<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Integration\Im\Notification\NotifySender;
use Bitrix\Intranet\Internal\Integration\Security\PersonalOtp;
use Bitrix\Intranet\Service\UserService;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Data\Storage\StorageInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

class RecoverAccessRequestService
{
	private StorageInterface $storage;
	private UserService $userService;
	private \Closure $notifyAvailableChecker;
	private \Closure $otpActiveChecker;

	public function __construct(
		?StorageInterface $storage = null,
		?UserService $userService = null,
		?\Closure $notifyAvailableChecker = null,
		?\Closure $otpActiveChecker = null,
	)
	{
		$this->storage = $storage ?? ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
		$this->userService = $userService ?? new UserService();
		$this->notifyAvailableChecker = $notifyAvailableChecker ?? static fn(): bool => NotifySender::isAvailable();
		$this->otpActiveChecker = $otpActiveChecker ?? static fn(User $user): bool => (new PersonalOtp($user))->isActivated();
	}

	public function isSent(User $user): bool
	{
		if (!$this->isOtpActive($user))
		{
			$this->clear($user);

			return false;
		}

		return $this->isStoredSent($user);
	}

	public function canSend(User $user): bool
	{
		if (!$this->isOtpActive($user))
		{
			return false;
		}

		return $this->canSendWhenOtpIsActive($user, $this->isStoredSent($user));
	}

	/**
	 * @return array{isSent: bool, canSend: bool}
	 */
	public function getStatus(User $user): array
	{
		if (!$this->isOtpActive($user))
		{
			$this->clear($user);

			return [
				'isSent' => false,
				'canSend' => false,
			];
		}

		$isSent = $this->isStoredSent($user);

		return [
			'isSent' => $isSent,
			'canSend' => $this->canSendWhenOtpIsActive($user, $isSent),
		];
	}

	public function markSent(User $user): void
	{
		$this->storage->set(
			$this->getStorageKey($user),
			[
				'sentAt' => time(),
			],
			3600,
		);
	}

	public function clear(User $user): void
	{
		$this->storage->delete($this->getStorageKey($user));
	}

	/**
	 * @return int[]
	 */
	public function getAdminIdsToNotify(User $user): array
	{
		$userId = (int)$user->getId();

		return array_values(
			array_filter(
				array_map('intval', $this->getAdminUserIds()),
				static fn(int $adminId): bool => $adminId > 0 && $adminId !== $userId,
			),
		);
	}

	/**
	 * @return int[]
	 */
	private function getAdminUserIds(): array
	{
		if (Loader::includeModule('bitrix24'))
		{
			return \CBitrix24::getAllAdminId();
		}

		return $this->userService->getAdminUserIds();
	}

	private function isNotifyAvailable(): bool
	{
		return ($this->notifyAvailableChecker)();
	}

	private function isOtpActive(User $user): bool
	{
		return ($this->otpActiveChecker)($user);
	}

	private function isStoredSent(User $user): bool
	{
		return $this->storage->has($this->getStorageKey($user));
	}

	private function canSendWhenOtpIsActive(User $user, bool $isSent): bool
	{
		return !$isSent
			&& $this->isNotifyAvailable()
			&& !empty($this->getAdminIdsToNotify($user))
		;
	}

	private function getStorageKey(User $user): string
	{
		return 'intranet.otp.recover_access_request.' . (int)$user->getId();
	}
}
