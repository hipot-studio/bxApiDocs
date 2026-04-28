<?php

namespace Bitrix\Intranet\Settings\Tools;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

class NoteBase extends Tool
{
	public function getId(): string
	{
		return 'note_base';
	}

	public function getName(): string
	{
		return Loc::getMessage('INTRANET_SETTINGS_TOOLS_NOTE_BASE_MAIN') ?? '';
	}

	public function isAvailable(): bool
	{
		return ModuleManager::isModuleInstalled('note');
	}

	public function getSubgroupsIds(): array
	{
		return [];
	}

	public function getSubgroups(): array
	{
		return [];
	}

	public function getLeftMenuPath(): ?string
	{
		return '/note/';
	}

	public function getSettingsPath(): ?string
	{
		return $this->getLeftMenuPath();
	}

	public function getMenuItemId(): ?string
	{
		return 'menu_note_base';
	}
}
