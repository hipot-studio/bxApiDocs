<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI;

use Bitrix\AI\Dto\PromptDto;
use Bitrix\AI\Dto\PromptType;
use Bitrix\Im\V2\Chat;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

/**
 * Проектные промпты (suggests) для баннера copilot-чата внутри проекта (collab).
 *
 * Подменяют промпты дефолтной роли на проектный набор, когда родитель чата — CollabChat.
 * Работают на копии роль-данных из RoleManager::getRoles() и не мутируют его статический кэш.
 */
final class ProjectCopilotPrompts
{
	/** @var list<string> синтетические стабильные коды проектных промптов */
	private const PROMPT_CODES = [
		'project_how_to',
		'project_status',
		'project_task',
		'project_help',
	];

	/**
	 * Подменяет prompts дефолтной роли на проектные для чатов, чей родитель — CollabChat.
	 *
	 * @param array $roles роль-данные (ключ — roleCode), как из RoleManager::getRoles()
	 * @param Chat\GroupChat[] $chats чаты, для которых собраны роли
	 */
	public function substituteForChats(array $roles, array $chats): array
	{
		if (empty($roles) || empty($chats))
		{
			return $roles;
		}

		$defaultRoleCode = RoleManager::getDefaultRoleCode();
		if ($defaultRoleCode === null || !isset($roles[$defaultRoleCode]))
		{
			return $roles;
		}

		foreach ($chats as $chat)
		{
			if (
				$chat instanceof Chat\GroupChat
				&& $chat->getCopilotRole() === $defaultRoleCode
				&& $this->isProjectChat($chat)
			)
			{
				// набор строим лениво — только когда проектный чат подтверждён,
				// чтобы не тратить Loc-лукапы и аллокации на непроектных сборках
				$prompts = $this->getPrompts();
				if (!empty($prompts))
				{
					$roles[$defaultRoleCode]['prompts'] = $prompts;
				}
				break;
			}
		}

		return $roles;
	}

	private function isProjectChat(Chat $chat): bool
	{
		return $chat->hasParent() && $chat->getParentChat() instanceof Chat\CollabChat;
	}

	/**
	 * Хардкод проектных промптов. Пусто, если модуль ai не подключён (нужен для PromptDto).
	 *
	 * @return PromptDto[]
	 */
	public function getPrompts(): array
	{
		if (!Loader::includeModule('ai'))
		{
			return [];
		}

		$prompts = [];
		foreach (self::PROMPT_CODES as $index => $code)
		{
			$phrase = (string)Loc::getMessage('IM_COPILOT_PROJECT_SUGGEST_PHRASE_' . ($index + 1));
			$prompts[] = new PromptDto(
				$code,
				PromptType::DEFAULT,
				$phrase,
				$phrase,
				false,
			);
		}

		return $prompts;
	}
}
