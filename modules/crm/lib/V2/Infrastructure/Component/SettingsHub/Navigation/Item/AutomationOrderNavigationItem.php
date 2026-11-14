<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Integration\Intranet\ToolsManager;
use Bitrix\Crm\Service\Router;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;

Loc::loadMessages(__FILE__);

final class AutomationOrderNavigationItem implements SettingsHubUrlNavigationItem
{
	public function __construct(
		private Router $router,
		private bool $isRobotsAvailable,
		private bool $isAutomationAvailable,
		private bool $isWithOrdersMode,
	)
	{
	}

	public function id(): string
	{
		return 'automation-order';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return $permissions->isCrmAdmin() && $this->isWithOrdersMode;
	}

	public function checkAvailability(UserPermissions $permissions): Result
	{
		if (!$this->isRobotsAvailable)
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_AUTOMATIONORDER_ROBOTS_TOOL_OFF'),
					'AUTOMATIONORDER_ROBOTS_TOOL_OFF',
					['restrictionSliderCode' => ToolsManager::ROBOTS_SLIDER_CODE],
				),
			);
		}

		if ($this->isAutomationAvailable)
		{
			return new Result();
		}

		return (new Result())->addError(
			new Error(
				Loc::getMessage('CRM_SETTINGS_HUB_AUTOMATIONORDER_AUTOMATION_UNAVAILABLE'),
				'AUTOMATIONORDER_AUTOMATION_UNAVAILABLE',
				['restrictionSliderCode' => 'limit_shop_robots'],
			),
		);
	}

	public function url(): ?Uri
	{
		return $this->router->getAutomationUrl(\CCrmOwnerType::Order, 0);
	}
}
