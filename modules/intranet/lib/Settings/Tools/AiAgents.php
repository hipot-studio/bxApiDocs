<?php

namespace Bitrix\Intranet\Settings\Tools;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

final class AiAgents extends Tool
{
	public function getId(): string
	{
		return 'ai_agents';
	}

	public function getName(): string
	{
		return Loc::getMessage('INTRANET_SETTINGS_TOOLS_AI_AGENTS_MAIN') ?? '';
	}

	public function isAvailable(): bool
	{
		return ModuleManager::isModuleInstalled('bizproc')
			&& \Bitrix\Main\Config\Option::get('bizproc', 'feature_ai_agents', 'N') === 'Y';
	}

	public function getSubgroupsIds(): array
	{
		return [];
	}

	public function getSubgroups(): array
	{
		return [];
	}

	public function getMenuItemId(): ?string
	{
		return 'menu_ai_agents';
	}

	public function getLeftMenuPath(): ?string
	{
		return '/bizproc/ai/agents/';
	}
}
