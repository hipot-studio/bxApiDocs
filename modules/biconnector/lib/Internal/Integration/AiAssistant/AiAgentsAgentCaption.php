<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant;

use Bitrix\Im\V2\Integration\AI\EngineManager;
use Bitrix\Main\Loader;
use Bitrix\Ui\Public\Services\Copilot\CopilotNameService;

/**
 * Names the agent that will answer a task launched from the section: the copilot name of the portal zone
 * and the engine a new copilot chat of the user gets (the last one they chose, else the default).
 */
class AiAgentsAgentCaption
{
	public function getName(int $userId): string
	{
		return $this->getEngineName($userId) ?? $this->getCopilotName();
	}

	protected function getCopilotName(): string
	{
		return Loader::includeModule('ui') ? (new CopilotNameService())->getCopilotName() : 'BitrixGPT';
	}

	protected function getEngineName(int $userId): ?string
	{
		if (!Loader::includeModule('im') || !Loader::includeModule('ai'))
		{
			return null;
		}

		$manager = new EngineManager();
		$code = $manager->getLastSelectedEngineCode($userId);
		$name = $manager->validateEngineCode($code)
			? $manager->getEngineNameByCode($code)
			: EngineManager::getDefaultEngineName()
		;

		return is_string($name) && $name !== '' ? $name : null;
	}
}
