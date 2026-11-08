<?php

namespace Bitrix\Timeman\Integration\Intranet;

use Bitrix\Intranet\Settings\Tools\Company;
use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Loader;

final class Settings
{
	public const TOOLS = [
		'worktime' => 'worktime',
		'absence' => 'absence',
	];

	private function isAvailable(): bool
	{
		$toolsManagerClass = '\Bitrix\Intranet\Settings\Tools\ToolsManager';

		return Loader::includeModule('intranet') && class_exists($toolsManagerClass);
	}

	public function isToolAvailable(string $tool): bool
	{
		if (!$this->isAvailable() || !in_array($tool, self::TOOLS))
		{
			return true;
		}

		if ($tool === self::TOOLS['worktime'])
		{
			// read the tool state directly: the menu cache behind ToolsManager depends on the current user
			return (new Company())->isEnabledSubgroupById(self::TOOLS['worktime']);
		}

		return (ToolsManager::getInstance())->checkAvailabilityByToolId($tool);
	}
}
