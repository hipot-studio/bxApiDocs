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
		if (Collab::isNewProjectsAvailable())
		{
			return 'IM_FOLDER_SYSTEM_PROJECTS';
		}

		return 'IM_FOLDER_SYSTEM_COLLABS';
	}

	/** Derived from the title key so that the "projects / collabs" branch cannot drift apart. */
	public function getDescriptionLangKey(): ?string
	{
		return $this->getTitleLangKey() . '_DESCRIPTION';
	}

	public function isAvailable(int $userId): bool
	{
		return Collab::isAvailable();
	}
}
