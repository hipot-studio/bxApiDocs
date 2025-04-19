<?php

namespace Bitrix\Tasks\Integration\Intranet;

use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Loader;

final class Settings
{
	public const TOOLS = [
		'base_tasks' => 'base_tasks',
		'projects' => 'projects',
		'scrum' => 'scrum',
		'departments' => 'departments',
		'effective' => 'effective',
		'employee_plan' => 'employee_plan',
		'report' => 'report',
		'templates' => 'templates',
		'flows' => 'flows',
		'crm_bi' => 'crm_bi',
	];

	private static ?self $instance = null;

	public static function getInstance(): self
	{
		if (self::$instance === null)
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function isToolAvailable(string $tool): bool
	{
		if (!$this->isAvailable() || !array_key_exists($tool, self::TOOLS))
		{
			return true;
		}

		return (new ToolsManager())->checkAvailabilityByToolId($tool);
	}

	public function isToolAvailableByMenuId(string $menuItemId): bool
	{
		if (!$this->isAvailable())
		{
			return true;
		}

		return (new ToolsManager())->checkAvailabilityByMenuId($menuItemId);
	}

	private function isAvailable(): bool
	{
		return Loader::includeModule('intranet');
	}
}
