<?php

namespace Bitrix\Im\V2\Folder\System;

use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserType;
use Bitrix\Im\V2\Folder\Definition\FolderDefinition;
use Bitrix\Im\V2\Folder\Definition\UnsupportedDefinition;

/**
 * "All open channels" — sort-anchor only. Chat-level operations (pin/tail)
 * are forbidden via `instanceof` checks in services and skipped during
 * legacy-pin migration / fan-out.
 */
final class AllOpenChannelFolder extends SystemFolder
{
	public const CODE = 'openChannel';
	public const RECENT_SECTION = 'openChannel';

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
		return 4;
	}

	public function getTitleLangKey(): string
	{
		return 'IM_FOLDER_SYSTEM_OPEN_CHANNEL';
	}

	public function getDescriptionLangKey(): ?string
	{
		return 'IM_FOLDER_SYSTEM_OPEN_CHANNEL_DESCRIPTION';
	}

	public function isAvailable(int $userId): bool
	{
		try
		{
			$user = User::getInstance($userId);

			return $user->getType() === UserType::USER;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public function shouldMigrateLegacyPins(): bool
	{
		return false;
	}

	/** Showcase/catalog of open channels: no standard recent list, tail not supported. */
	public function getDefinition(): FolderDefinition
	{
		return new UnsupportedDefinition($this->getRecentSection());
	}
}
