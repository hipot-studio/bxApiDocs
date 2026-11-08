<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\System;

use Bitrix\Im\Integration\Vibecodeconnector\Catalog;
use Bitrix\Im\V2\Application\Features;

final class VibecodeAppFolder extends SystemFolder
{
	public const CODE = 'vibecodeApp';
	// Keep in sync with vibecodeconnector ENTITY_TYPE after RecentConfigManager::loadExternal() normalization.
	public const RECENT_SECTION = 'vibecodeApp';

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
		return 7;
	}

	public function getTitleLangKey(): string
	{
		return 'IM_FOLDER_SYSTEM_VIBECODE_APP';
	}

	public function getDescriptionLangKey(): ?string
	{
		return 'IM_FOLDER_SYSTEM_VIBECODE_APP_DESCRIPTION';
	}

	/**
	 * Portal gate plus a per-user rule: the folder is pointless for those who have no access
	 * to the app catalog at all.
	 */
	public function isAvailable(int $userId): bool
	{
		return Features::isVibecodeAppsFolderAvailable() && Catalog::isAvailableForUser($userId);
	}

	public function displaysNestedInRoot(): bool
	{
		return true;
	}

	public function shouldMigrateLegacyPins(): bool
	{
		return false;
	}
}
