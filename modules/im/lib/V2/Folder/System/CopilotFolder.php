<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\System;

use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Integration\AI\CopilotNameResolver;

final class CopilotFolder extends SystemFolder
{
	public const CODE = 'copilot';
	public const RECENT_SECTION = 'copilot';

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
		return 3;
	}

	public function getTitleLangKey(): string
	{
		return 'IM_FOLDER_SYSTEM_COPILOT';
	}

	public function isAvailable(int $userId): bool
	{
		return CopilotChat::isActive();
	}

	public function getTitle(): string
	{
		$name = CopilotNameResolver::getInstance()->getName();

		return $name !== '' ? $name : parent::getTitle();
	}
}
