<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\System;

use Bitrix\Im\V2\Application\Features;

/**
 * "Chats" source of the composite {@see AllFolder}. Root-scoped, hidden from the
 * tab bar - exists so "All" can reference it by code and clients keep it out of
 * the navigation until it becomes the primary "Chats" entry.
 */
final class ChatFolder extends SystemFolder
{
	public const CODE = 'chat';
	public const RECENT_SECTION = 'chat';

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
		return 8;
	}

	public function getTitleLangKey(): string
	{
		return 'IM_FOLDER_SYSTEM_CHAT';
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
