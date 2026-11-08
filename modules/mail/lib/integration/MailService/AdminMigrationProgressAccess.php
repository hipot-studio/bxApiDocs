<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\MailService;

use Bitrix\Mail\Access\Model\UserModel;
use Bitrix\Main\Config\Option;

final class AdminMigrationProgressAccess
{
	public const EARLY_DISPLAY_OPTION = 'migration_admin_progress_early_visibility';

	public function canRead(int $userId): bool
	{
		return $userId > 0 && UserModel::createFromId($userId)->isAdmin();
	}

	public function canControl(int $userId): bool
	{
		return $this->canRead($userId);
	}

	public function isEarlyDisplayEnabled(): bool
	{
		return Option::get('mail', self::EARLY_DISPLAY_OPTION, 'N') === 'Y';
	}

	public function canShowProgress(bool $regularlyVisible): bool
	{
		return $regularlyVisible || $this->isEarlyDisplayEnabled();
	}
}
