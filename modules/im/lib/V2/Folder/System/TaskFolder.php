<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\System;

use Bitrix\Im\V2\Application\Features;

final class TaskFolder extends SystemFolder
{
	public const CODE = 'tasksTask';
	public const RECENT_SECTION = 'tasksTask';

	public function getCode(): string
	{
		return self::CODE;
	}

	public function getRecentSection(): string
	{
		return self::RECENT_SECTION;
	}

	public function getDefaultPosition(): int
	{
		return 2;
	}

	public function getTitleLangKey(): string
	{
		return 'IM_FOLDER_SYSTEM_TASKS';
	}

	public function isAvailable(int $userId): bool
	{
		return Features::isTasksRecentListAvailable();
	}

	public function displaysNestedInRoot(): bool
	{
		return true;
	}
}
