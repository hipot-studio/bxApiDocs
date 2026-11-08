<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\System;

use Bitrix\Im\V2\Application\Features;

/**
 * "My channels" source of the composite {@see AllFolder}. Root-scoped, hidden
 * from the tab bar - referenced by "All" through its code.
 */
final class ChannelFolder extends SystemFolder
{
	public const CODE = 'channel';
	public const RECENT_SECTION = 'channel';

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
		return 9;
	}

	/**
	 * Reads the same as IM_FOLDER_SYSTEM_OPEN_CHANNEL on purpose: this folder is invisible
	 * and the two never meet on a user surface - the open-channel showcase is another folder.
	 */
	public function getTitleLangKey(): string
	{
		return 'IM_FOLDER_SYSTEM_CHANNEL';
	}

	public function getDescriptionLangKey(): ?string
	{
		return 'IM_FOLDER_SYSTEM_CHANNEL_DESCRIPTION';
	}

	public function isAvailable(int $userId): bool
	{
		return Features::isChatFolderAllAvailable();
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
