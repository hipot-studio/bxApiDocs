<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\System;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Integration\Socialnetwork\Collab\Collab;

final class DefaultFolder extends SystemFolder
{
	public const CODE = 'default';
	public const RECENT_SECTION = 'default';

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
		return 1;
	}

	public function getTitleLangKey(): string
	{
		return 'IM_FOLDER_SYSTEM_DEFAULT';
	}

	/**
	 * The title does not branch, the subtitle does: it names the chat kinds it collects,
	 * and collabs are called projects once {@see Collab::isNewProjectsAvailable} is on.
	 */
	public function getDescriptionLangKey(): ?string
	{
		if (Collab::isNewProjectsAvailable())
		{
			return 'IM_FOLDER_SYSTEM_DEFAULT_DESCRIPTION_V2';
		}

		return 'IM_FOLDER_SYSTEM_DEFAULT_DESCRIPTION';
	}

	public function isAvailable(int $userId): bool
	{
		return true;
	}

	/**
	 * Mirror of {@see AllFolder}: "All" takes over the navigation entry, so exactly one of the two
	 * is visible at a time. Availability stays unconditional on purpose - the folder must keep
	 * reaching the client, which hides it by this flag rather than by the code 'default'.
	 */
	public function isVisible(): bool
	{
		return !Features::isChatFolderAllAvailable();
	}
}
