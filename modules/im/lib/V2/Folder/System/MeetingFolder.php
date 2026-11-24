<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\System;

use Bitrix\Im\V2\Application\Features;

/**
 * "Meetings" - the visible folder over the 'calendar' recent section. Flattens nested
 * chats into the root list, so the meeting chat of a project is shown here and not only
 * inside the project; the root frame belongs to its composite source
 * {@see MeetingRootFolder}, which shares the very same section.
 */
final class MeetingFolder extends SystemFolder
{
	public const CODE = 'meeting';
	public const RECENT_SECTION = 'calendar';

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
		return 11;
	}

	public function getTitleLangKey(): string
	{
		return 'IM_FOLDER_SYSTEM_MEETING';
	}

	public function getDescriptionLangKey(): ?string
	{
		return 'IM_FOLDER_SYSTEM_MEETING_DESCRIPTION';
	}

	/**
	 * A gate of its own instead of the folder-family flag: rolling this folder back must not
	 * take the rest of the web messenger folders down with it.
	 */
	public function isAvailableOnPortal(): bool
	{
		return Features::isMeetingsFolderAvailable();
	}

	public function displaysNestedInRoot(): bool
	{
		return true;
	}

	public function canReadAll(): bool
	{
		return true;
	}
}
