<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\System;

use Bitrix\Im\V2\Application\Features;

/**
 * "Tasks" source of the composite {@see AllFolder}. Shares the recent section
 * with the visible {@see TaskFolder} but keeps its own code ('tasksTaskRoot')
 * to bypass the unique (USER_ID, PARENT_CHAT_ID, CODE) key, and stays
 * root-scoped: unlike {@see TaskFolder} it does not flatten nested chats into
 * the root list. Hidden from the tab bar.
 */
final class TaskRootFolder extends SystemFolder
{
	public const CODE = 'tasksTaskRoot';
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
		return 10;
	}

	public function getTitleLangKey(): string
	{
		return 'IM_FOLDER_SYSTEM_TASKS_ROOT';
	}

	public function getDescriptionLangKey(): ?string
	{
		return 'IM_FOLDER_SYSTEM_TASKS_ROOT_DESCRIPTION';
	}

	public function isAvailable(int $userId): bool
	{
		return Features::isChatFolderAllAvailable() && Features::isTasksRecentListAvailable();
	}

	/**
	 * Composite member: its pin composition is owned by the composite resolver,
	 * so the legacy global pins carry no shadow of their own here.
	 */
	public function shouldMigrateLegacyPins(): bool
	{
		return false;
	}

	public function isVisible(): bool
	{
		return false;
	}
}
