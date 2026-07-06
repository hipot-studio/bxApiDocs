<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\System;

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

	public function isAvailable(int $userId): bool
	{
		return true;
	}
}
