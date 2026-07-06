<?php

namespace Bitrix\Im\V2\Folder\System;

use Bitrix\Im\V2\Integration\Socialnetwork\Collab\Collab;

final class CollabFolder extends SystemFolder
{
	public const CODE = 'collab';
	public const RECENT_SECTION = 'collab';

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
		return 5;
	}

	public function getTitleLangKey(): string
	{
		return 'IM_FOLDER_SYSTEM_COLLABS';
	}

	public function isAvailable(int $userId): bool
	{
		return Collab::isAvailable();
	}
}
