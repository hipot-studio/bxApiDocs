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
		return 'IM_FOLDER_SYSTEM_COPILOT_MSGVER_1';
	}

	public function getTitleReplacements(): array
	{
		return ['#COPILOT_NAME#' => CopilotNameResolver::getInstance()->getName()];
	}

	public function getDescriptionLangKey(): ?string
	{
		return 'IM_FOLDER_SYSTEM_COPILOT_DESCRIPTION';
	}

	public function isAvailable(int $userId): bool
	{
		return CopilotChat::isActive();
	}

	public function getTitle(): string
	{
		$title = parent::getTitle();

		// Safety net for a locale with no phrase file or key; every shipped locale declares the phrase.
		return $title !== '' ? $title : CopilotNameResolver::getInstance()->getName();
	}
}
