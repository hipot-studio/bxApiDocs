<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Notification;

use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Integration\Bitrix24\PortalInfoService;
use Bitrix\Main\Config\Option;

class NewHelperNotificationService implements NotificationService
{
	private const MODULE_ID = 'intranet';
	private const END_TIMESTAMP_UTC = 1793491200; // 2026-11-01 00:00:00 UTC
	protected const OPTION_IS_NEW_HELPDESK = 'isNewHelpdesk';
	protected const USER_OPTION_CATEGORY = 'intranet';
	protected const USER_OPTION_NAME = 'new_helper_notification_shown';
	protected const USER_OPTION_VALUE = 'Y';

	public function __construct(
		private readonly ?CurrentUser $currentUser,
		private readonly ?PortalInfoService $portalInfoService,
		private readonly ?int $currentTimestamp,
		private readonly ?User $user = null,
	)
	{
	}

	public static function createForCurrentUser(): self
	{
		return new self(
			CurrentUser::get(),
			new PortalInfoService(),
			time(),
		);
	}

	public function isAvailable(): bool
	{
		return Option::get(self::MODULE_ID, self::OPTION_IS_NEW_HELPDESK, 'N') === 'Y'
			&& $this->portalInfoService?->isCis() === false
			&& $this->currentTimestamp !== null
			&& $this->currentTimestamp < self::END_TIMESTAMP_UTC;
	}

	public function needToShow(): bool
	{
		if (!$this->isAvailable())
		{
			return false;
		}

		if (!$this->currentUser?->isAuthorized())
		{
			return false;
		}

		$userId = (int)$this->currentUser->getId();

		if ($userId <= 0 || $this->isAlreadyShownForUser($userId))
		{
			return false;
		}

		$user = $this->user ?? new User($userId);

		return $user->isIntranet()
			&& !$user->isIntegrator()
			&& !$user->isCollaber();
	}

	public function setShownForUser(): void
	{
		\CUserOptions::SetOption(
			self::USER_OPTION_CATEGORY,
			self::USER_OPTION_NAME,
			self::USER_OPTION_VALUE,
			false,
			$this->currentUser->getId(),
		);
	}

	private function isAlreadyShownForUser(int $userId): bool
	{
		return \CUserOptions::GetOption(
			self::USER_OPTION_CATEGORY,
			self::USER_OPTION_NAME,
			'N',
			$userId,
		) === self::USER_OPTION_VALUE;
	}
}
