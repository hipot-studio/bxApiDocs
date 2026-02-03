<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Application\Navigation;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Marketplace\Application;
use Bitrix\Im\V2\Marketplace\Placement;
use Bitrix\Im\V2\Permission;
use Bitrix\Im\V2\Permission\GlobalAction;
use Bitrix\Main\Localization\Loc;

/**
 * Class for creating navigation menu items.
 *
 * Provides method for creating menu items list based on configuration
 * including menu items for market applications.
 */
class MenuItemProvider
{
	use ContextCustomer;

	public const SORT_CHAT = 100;
	// TODO: replace with event
	public const SORT_TASKS = 200;
	public const SORT_COPILOT = 300;
	public const SORT_COLLAB = 400;
	public const SORT_CHANNEL = 500;
	public const SORT_OPENLINES = 600;
	public const SORT_OPENLINESV2 = 700;
	public const SORT_NOTIFICATIONS = 800;
	public const SORT_CALL = 900;
	public const SORT_MARKET = 1000;
	public const SORT_SETTINGS = 1100;

	protected Features $applicationFeatures;

	protected array $phoneSettings;

	public function __construct()
	{
		$this->applicationFeatures = Features::get();
		$this->phoneSettings = \CIMMessenger::getPhoneSettings();
	}

	/**
	 * Returns collection of all menu items objects.
	 */
	public function getMenuItems(): MenuItemCollection
	{
		$collection = new MenuItemCollection($this->getDefaultMenuItems());

		$this->fillExternalChatsItems($collection);

		return $collection;
	}

	/**
	 * Returns array of all default menu items objects.
	 *
	 * @return MenuItem[]
	 */
	public function getDefaultMenuItems(): array
	{
		return [
			new MenuItem(
				id: 'chat',
				text: Loc::getMessage('IM_NAVIGATION_MENU_CHATS'),
				sort: self::SORT_CHAT,
			),
			// TODO: replace with event
			new MenuItem(
				id: 'tasksTask',
				text: Loc::getMessage('IM_NAVIGATION_MENU_TASKS_MSGVER_1'),
				isVisible: $this->applicationFeatures->isTasksRecentListAvailable,
				sort: self::SORT_TASKS,
			),
			new MenuItem(
				id: 'copilot',
				text: Loc::getMessage('IM_NAVIGATION_MENU_COPILOT'),
				isVisible: $this->applicationFeatures->copilotAvailable,
				sort: self::SORT_COPILOT,
			),
			new MenuItem(
				id: 'collab',
				text: Loc::getMessage('IM_NAVIGATION_MENU_COLLAB'),
				isVisible: $this->applicationFeatures->collabAvailable,
				sort: self::SORT_COLLAB,
			),
			new MenuItem(
				id: 'channel',
				text: Loc::getMessage('IM_NAVIGATION_MENU_CHANNELS'),
				isVisible: $this->isGlobalActionPermitted(GlobalAction::GetChannels),
				sort: self::SORT_CHANNEL,
			),
			new MenuItem(
				id: 'openlines',
				text: Loc::getMessage('IM_NAVIGATION_MENU_OPENLINES'),
				isVisible: (
					!$this->applicationFeatures->openLinesV2
					&& $this->isGlobalActionPermitted(GlobalAction::GetOpenlines)
				),
				sort: self::SORT_OPENLINES,
			),
			new MenuItem(
				id: 'openlinesV2',
				text: Loc::getMessage('IM_NAVIGATION_MENU_OPENLINES'),
				isVisible: (
					$this->applicationFeatures->openLinesV2
					&& $this->isGlobalActionPermitted(GlobalAction::GetOpenlines)
				),
				sort: self::SORT_OPENLINESV2,
			),
			new MenuItem(
				id: 'notification',
				text: Loc::getMessage('IM_NAVIGATION_MENU_NOTIFICATIONS'),
				isVisible: !$this->applicationFeatures->isNotificationsStandalone,
				sort: self::SORT_NOTIFICATIONS,
			),
			new MenuItem(
				id: 'call',
				text: Loc::getMessage('IM_NAVIGATION_MENU_CALLS_V2'),
				isVisible: (
					$this->phoneSettings['phoneEnabled']
					&& $this->phoneSettings['canPerformCallsByUser']
				),
				sort: self::SORT_CALL,
			),
			new MenuItem(
				id: 'market',
				text: Loc::getMessage('IM_NAVIGATION_MENU_MARKET_TITLE_MSGVER_1'),
				isVisible: $this->isGlobalActionPermitted(GlobalAction::GetMarket),
				sort: self::SORT_MARKET,
			),
			...$this->getMarketAppMenuItems(),
			new MenuItem(
				id: 'settings',
				text: Loc::getMessage('IM_NAVIGATION_MENU_SETTINGS'),
				sort: self::SORT_SETTINGS,
			),
		];
	}

	/**
	 * Creates menu item for each market app, placed in navigation
	 * and returns array of menu item objects.
	 *
	 * @return MenuItem[]
	 */
	public function getMarketAppMenuItems(): array
	{
		$applications = (new Application())->getApplications([Placement::IM_NAVIGATION]);
		usort($applications, function($a, $b) {
			return $a->getOrder() - $b->getOrder();
		});

		$menuItems = [];
		$sort = self::SORT_MARKET;

		foreach ($applications as $application)
		{
			$sort++;
			$menuItems[] = new MenuItem(
				id: 'market',
				text: $application->getTitle(),
				entityId: $application->getId(),
				sort: $sort
			);
		}

		return $menuItems;
	}

	protected function fillExternalChatsItems(MenuItemCollection $collection): void
	{
		$event = new NavigationMenuBuildEvent($collection);
		$event->send();
	}

	protected function isGlobalActionPermitted(GlobalAction $action): bool
	{
		return Permission::canDoGlobalAction(
			$this->getContext()->getUserId(),
			$action,
			null,
		);
	}
}
