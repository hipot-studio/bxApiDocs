<?php

use Bitrix\Intranet\Integration\Templates\Air\ChatMenu;
use Bitrix\Intranet\Integration\Templates\Air\CollaborationWebMenu;
use Bitrix\Intranet\Site\Sections\CollaborationSection;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class IntranetChatBarComponent extends \CBitrixComponent
{
	public function executeComponent(): void
	{
		if ((int)CurrentUser::get()->getId() <= 0 || !Loader::includeModule('im') || !Loader::includeModule('intranet'))
		{
			return;
		}

		$collaborationMenuId = CollaborationWebMenu::getMenuId('messenger');
		$availableMenuIds = $collaborationMenuId !== null
			? [$collaborationMenuId]
			: ['top_menu_id_collaboration', 'chat-menu'];
		$existingMenuId = CollaborationWebMenu::getRenderedMenuId();
		$requestedPage = Application::getInstance()->getContext()->getRequest()->getRequestedPage();
		if (in_array($requestedPage, [SITE_DIR . 'online/', SITE_DIR . 'online/index.php'], true))
		{
			$existingMenuId = $collaborationMenuId
				?? (CollaborationSection::shouldShowNewStructure() ? 'top_menu_id_collaboration' : 'chat-menu');
		}
		if (!in_array($existingMenuId, $availableMenuIds, true))
		{
			$existingMenuId = '';
		}

		$this->arResult['EXISTING_MENU_ID'] = $existingMenuId;
		$this->arResult['IS_COLLABORATION_MENU'] = $collaborationMenuId !== null;
		if ($existingMenuId !== '')
		{
			$this->arResult['MENU_ID'] = $existingMenuId;
		}
		else
		{
			$menu = CollaborationWebMenu::getMenu('messenger');
			$this->arResult['MENU_ID'] = $menu['ID'] ?? 'chat-menu';
			$this->arResult['MENU_ITEMS'] = $menu['ITEMS'] ?? ChatMenu::getMenuItems();
			$this->arResult['IS_COLLABORATION_MENU'] = $menu !== null;
			if ($menu !== null)
			{
				$this->arResult['MENU_CLIENT_CONFIG'] = $menu['CLIENT_CONFIG'];
			}
		}

		$this->includeComponentTemplate();
	}
}
