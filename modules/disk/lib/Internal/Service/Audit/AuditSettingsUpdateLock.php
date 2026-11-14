<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Main\Application;

class AuditSettingsUpdateLock
{
	private const LOCK_NAME = 'disk.audit.settings.update';
	private const LOCK_TIMEOUT = 5;

	public function acquire(): bool
	{
		return Application::getConnection()->lock(self::LOCK_NAME, self::LOCK_TIMEOUT);
	}

	public function release(): void
	{
		Application::getConnection()->unlock(self::LOCK_NAME);
	}
}
