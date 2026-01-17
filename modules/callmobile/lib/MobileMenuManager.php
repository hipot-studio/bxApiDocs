<?php

namespace Bitrix\CallMobile;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Mobile\Menu\Manager\MobileMenuManager as BaseMobileMenuManager;
use Bitrix\Mobile\Menu\MenuList;

Loc::loadMessages(__FILE__);

class MobileMenuManager extends BaseMobileMenuManager
{
	public static function onMobileMenuStructureBuilt(array $menu, $context): array
	{
		if (!Loader::includeModule('call') || !Loader::includeModule('callmobile'))
		{
			return $menu;
		}

		$title = Loc::getMessage('CALLMOBILE_MENU_CALL_LIST');

		$item = [
			'id' => 'call_list',
			'title' => $title,
			'imageName' => 'phone_up',
			'counter' => 'call_list',
			'params' => [
				'onclick' => \Bitrix\Mobile\Tab\Utils::getComponentJSCode([
					'name' => 'JSStackComponent',
					'title' => $title,
					'componentCode' => 'call:callList',
					'scriptPath' => \Bitrix\MobileApp\Janative\Manager::getComponentPath('call:callList'),
					'rootWidget' => [
						'name' => 'layout',
						'settings' => [
							'objectName' => 'layout',
							'useLargeTitleMode' => true,
							'titleParams' => [
								'useLargeTitleMode' => true,
								'text' => $title,
							],
						],
					],
					'params' => [
						'COMPONENT_CODE' => 'call:callList',
						'USER_ID' => $context->userId ?? 0,
						'SITE_ID' => $context->siteId ?? SITE_ID,
						'IS_CREATE_CALL_BUTTON_ENABLED' => \Bitrix\Call\Settings::isCreateCallButtonEnabled(),
					],
				]),
				'counter' => 'call_list',
			],
		];

		return self::addMenuItem($menu, MenuList::SECTION_CALL_LIST, $item);
	}
}


