<?php

namespace Bitrix\Intranet\Controller;

use Bitrix\Intranet\Internal\Integration\Socialnetwork\WorkgroupLink;
use Bitrix\Intranet\Internal\Service\LeftMenu\GroupFavoritesService;
use Bitrix\Intranet\Internal\Service\LeftMenu\StandardItemIdentity;
use Bitrix\Intranet\Settings\Tools\Crm;
use Bitrix\Intranet\Settings\Tools\Sites;
use Bitrix\Intranet\Settings\Tools\Tasks;
use Bitrix\Intranet\Settings\Tools\TeamWork;
use Bitrix\Intranet\Portal\FirstPage;
use Bitrix\Intranet\Internal\Service\Portal\PortalFirstPageUrlNormalizer;
use Bitrix\Intranet\Internal\Service\Portal\FirstPageUrlPolicy;
use Bitrix\Intranet\UI\LeftMenu\MenuItem\ItemUserFavorites;
use Bitrix\Intranet\UI\LeftMenu\Preset;
use Bitrix\Intranet\Internal\MainTool\AccessPolicy;
use Bitrix\Intranet\Internal\MainTool\Repository;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\Error;
use Bitrix\Main\EventManager;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Type\Collection;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Socialnetwork\WorkgroupFavoritesTable;
use Bitrix\Socialnetwork\WorkgroupSiteTable;
use Bitrix\Socialnetwork\WorkgroupTable;

class LeftMenu extends \Bitrix\Main\Engine\Controller
{
	protected function getDefaultPreFilters()
	{
		return [
			new ActionFilter\Authentication(),
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			new ActionFilter\Csrf(),
			new ActionFilter\CloseSession()
		];
	}

	public function configureActions(): array
	{
		return [
			'setPreset' => [
				'-prefilters' => [ActionFilter\CloseSession::class],
			],
		];
	}

	protected function processBeforeAction(\Bitrix\Main\Engine\Action $action)
	{
		parent::processBeforeAction($action);

		if (Loader::includeModule('intranet'))
		{
			\Bitrix\Intranet\Composite\CacheProvider::deleteUserCache();
		}

		FirstPage::getInstance()->clearCache();

		return true;
	}

	protected function isCurrentUserAdmin():bool
	{
		global $USER;

		return (
			$USER->isAdmin()
			|| (
				Loader::includeModule("bitrix24")
				&& \CBitrix24::IsPortalAdmin($USER->getID())
			)
		);
	}

	protected function getItemsSortOptionName():string
	{
		return 'left_menu_sorted_items_' . SITE_ID;
	}

	protected function setGroupToFavorites($groupId, $value = 'Y')
	{
		if (intval($groupId) && $GLOBALS['USER']->getId() && Loader::includeModule('socialnetwork'))
		{
			try
			{
				\Bitrix\Socialnetwork\Item\WorkgroupFavorites::set(array(
					'GROUP_ID' => intval($groupId),
					'USER_ID' => $GLOBALS['USER']->getId(),
					'VALUE' => $value === 'Y' ? 'Y' : 'N'
				));
			}
			catch (\Exception $e) {}
		}
	}

	public function addSelfItemAction()
	{
		$itemLink = $itemText = '';

		if (!isset($_POST['itemData']))
			$error = Loc::getMessage('INTRANET_LEFT_MENU_SELF_ITEM_UNKNOWN_ERROR');

		if (isset($_POST['itemData']['text']))
		{
			$itemText = trim($_POST['itemData']['text']);
			$itemText = \Bitrix\Main\Text\Emoji::encode($itemText);
		}
		if (empty($itemText))
		{
			$error = Loc::getMessage('INTRANET_LEFT_MENU_SELF_ITEM_EMPTY_ERROR');
		}

		if (isset($_POST['itemData']['link']))
		{
			$itemLink = trim($_POST['itemData']['link']);
			if (!preg_match('~^[/|http]~i', $itemLink))
				$error = Loc::getMessage('INTRANET_LEFT_MENU_SELF_ITEM_LINK_ERROR');
		}

		if (!empty($error))
		{
			$this->addError(new Error($error));
			return null;
		}

		$itemID = crc32($itemLink);
		$newItem = array(
			'TEXT' => $itemText,
			'LINK' => $itemLink,
			'ID' => $itemID,
			'NEW_PAGE' => (
				isset($_POST['itemData']['openInNewPage']) && $_POST['itemData']['openInNewPage'] == 'Y'
					? 'Y'
					: 'N'
			)
		);
		$selfItems = \CUserOptions::GetOption('intranet', 'left_menu_self_items_' . SITE_ID);

		if (is_array($selfItems) && !empty($selfItems))
		{
			foreach ($selfItems as $item)
			{
				if ($item["LINK"] == $newItem["LINK"])
				{
					$this->addError(new Error(Loc::getMessage("INTRANET_LEFT_MENU_SELF_ITEM_DUBLICATE_ERROR")));
					return null;
				}
			}
			$selfItems[] = $newItem;
		}
		else
		{
			$selfItems = array($newItem);
		}
		\CUserOptions::SetOption("intranet", "left_menu_self_items_" . SITE_ID, $selfItems);

		return [
			"itemId" => crc32($itemLink),
		];

	}

	public function updateSelfItemAction()
	{
		if (!isset($_POST["itemData"]))
			$error = Loc::getMessage("INTRANET_LEFT_MENU_SELF_ITEM_UNKNOWN_ERROR");

		$itemData = array(
			"ID" => $_POST["itemData"]["id"],
			"NEW_PAGE" => isset($_POST["itemData"]["openInNewPage"]) && $_POST["itemData"]["openInNewPage"] == "Y" ? "Y" : "N"
		);

		if (isset($_POST["itemData"]["text"]))
		{
			$itemData["TEXT"] = trim($_POST["itemData"]["text"]);
			$itemData["TEXT"] = \Bitrix\Main\Text\Emoji::encode($itemData["TEXT"]);
		}
		if (empty($itemData["TEXT"]))
		{
			$error = Loc::getMessage("INTRANET_LEFT_MENU_SELF_ITEM_EMPTY_ERROR");
		}

		if (isset($_POST["itemData"]["link"]))
		{
			$itemData["LINK"] = trim($_POST["itemData"]["link"]);
			if (!preg_match("~^[/|http]~i", $itemData["LINK"]))
				$error = Loc::getMessage("INTRANET_LEFT_MENU_SELF_ITEM_LINK_ERROR");
		}

		if (!empty($error))
		{
			$this->addError(new Error($error));
			return null;
		}

		$selfItems = \CUserOptions::GetOption("intranet", "left_menu_self_items_" . SITE_ID);
		if (is_array($selfItems) && !empty($selfItems))
		{
			foreach ($selfItems as $key => $item)
			{
				if ($item["ID"] == $_POST["itemData"]["id"])
				{
					$selfItems[$key] = $itemData;

					\CUserOptions::SetOption("intranet", "left_menu_self_items_". SITE_ID, $selfItems);
					break;
				}
			}
		}
	}

	public function deleteSelfItemAction($menuItemId)
	{
		if (!$menuItemId)
		{
			return;
		}

		$selfItems = \CUserOptions::GetOption('intranet', 'left_menu_self_items_' . SITE_ID);
		if (is_array($selfItems))
		{
			foreach ($selfItems as $key => $item)
			{
				if ($item['ID'] == $menuItemId)
				{
					unset($selfItems[$key]);
					break;
				}
			}

			if (!empty($selfItems))
			{
				\CUserOptions::SetOption('intranet', 'left_menu_self_items_' . SITE_ID, $selfItems);
			}
			else
			{
				\CUserOptions::DeleteOption("intranet", "left_menu_self_items_" . SITE_ID);
			}
		}
	}

	public function addStandartItemAction()
	{
		$itemLink = $itemText = '';

		if (isset($_POST['itemData']['text']))
		{
			$itemText = trim($_POST['itemData']['text']);
		}
		if (empty($itemText))
		{
			$error = Loc::getMessage('INTRANET_LEFT_MENU_SELF_ITEM_TEXT_ERROR');
		}

		if (isset($_POST['itemData']['link']))
		{
			$itemLink = trim($_POST['itemData']['link']);
			if (!preg_match('~^/~i', $itemLink))
				$error = Loc::getMessage('INTRANET_LEFT_MENU_SELF_ITEM_LINK_ERROR');
		}

		if (!empty($error))
		{
			$this->addError(new Error($error));
			return null;
		}

		$standardItems = \CUserOptions::GetOption('intranet', 'left_menu_standard_items_' . SITE_ID);
		$standardItems = is_array($standardItems) ? $standardItems : [];
		$userId = (int)$this->getCurrentUser()?->getId();
		$groupFavoritesService = new GroupFavoritesService();
		$linkState = $groupFavoritesService->resolveState($userId, $itemLink);
		if (!$groupFavoritesService->canAddLink($standardItems, $itemLink, $userId, $linkState))
		{
			$this->addError(new Error(Loc::getMessage('INTRANET_LEFT_MENU_SELF_ITEM_UNKNOWN_ERROR')));

			return null;
		}

		$state = $linkState?->isAccessibleProjectSection() ? $linkState : null;
		$providedItemId = isset($_POST['itemData']['id']) ? trim($_POST['itemData']['id']) : null;
		$standardItemIdentity = new StandardItemIdentity();
		$identity = $standardItemIdentity->resolve($itemLink, $providedItemId, $state);
		$itemLink = $identity['link'];
		$itemId = $identity['id'];

		$newItem = array(
			'TEXT' => $itemText,
			'LINK' => $itemLink,
			'ID' => $itemId
		);

		if (isset($_POST['itemData']['counterId']) && $_POST['itemData']['counterId'])
		{
			$newItem['COUNTER_ID'] = $_POST['itemData']['counterId'];
		}

		if (isset($_POST['itemData']['subLink']) && is_array($_POST['itemData']['subLink']))
		{
			$newItem['SUB_LINK'] = $_POST['itemData']['subLink']['URL'];
		}
		if (!empty($standardItems))
		{
			foreach ($standardItems as $item)
			{
				if (
					is_string($item['LINK'] ?? null)
					&& $standardItemIdentity->isSame($item['LINK'], $newItem['LINK'], $state)
				)
				{
					$this->addError(new Error(Loc::getMessage('INTRANET_LEFT_MENU_SELF_ITEM_DUBLICATE_ERROR')));
					return null;
				}
			}
			$standardItems[$itemId] = $newItem;
		}
		else
		{
			$standardItems = array($itemId => $newItem);
		}
		$this->saveStandardItems(SITE_ID, $standardItems);

		$workgroupLink = WorkgroupLink::parse($itemLink);
		if ($workgroupLink['isFeed'] ?? false)
		{
			$this->setGroupToFavorites($workgroupLink['groupId'], 'Y');
		}

		$response = [
			'itemId' => $itemId,
			'iconClass' => $state !== null ? ItemUserFavorites::PROJECT_ICON_CLASS : '',
			'bindSlider' => $state !== null ? 'Y' : 'N',
		];
		if ($state !== null)
		{
			$response['canonicalLink'] = $state->canonicalLink;
		}

		return $response;
	}

	public function updateStandartItemAction()
	{
		if (isset($_POST['itemId']))
		{
			$itemId = $_POST['itemId'];
		}
		else
		{
			$this->addError(new Error(Loc::getMessage('INTRANET_LEFT_MENU_SELF_ITEM_UNKNOWN_ERROR')));
			return null;
		}

		$itemText = '';
		if (isset($_POST['itemText']))
		{
			$itemText = trim($_POST['itemText']);
		}
		if (empty($itemText))
		{
			$this->addError(new Error(Loc::getMessage('INTRANET_LEFT_MENU_SELF_ITEM_TEXT_ERROR')));
			return null;
		}

		$standardItems = \CUserOptions::GetOption('intranet', 'left_menu_standard_items_' . SITE_ID);
		if (is_array($standardItems))
		{
			foreach($standardItems as $key => $item)
			{
				if ($item['ID'] == $itemId)
				{
					$standardItems[$key]['TEXT'] = $itemText;
					break;
				}
			}

			if (!empty($standardItems))
			{
				$this->saveStandardItems(SITE_ID, $standardItems);
			}
			else
			{
				$this->deleteStandardItems(SITE_ID);
			}
		}
	}

	public function deleteStandartItemAction()
	{
		$standardItems = \CUserOptions::GetOption('intranet', 'left_menu_standard_items_' . SITE_ID);

		if (is_array($standardItems))
		{
			$itemId = '';
			$itemLinkFromRequest = null;
			if (isset($_POST['itemData']['link']))
			{
				$itemLinkFromRequest = $_POST['itemData']['link'];
				$itemId = crc32($itemLinkFromRequest);
			}
			else if (isset($_POST['itemData']['id']))
			{
				$itemId = $_POST['itemData']['id'];
			}

			if (!$itemId)
			{
				$this->addError(new Error(Loc::getMessage('INTRANET_LEFT_MENU_SELF_ITEM_UNKNOWN_ERROR')));
				return null;
			}

			$itemLink = '';
			if ($itemLinkFromRequest !== null)
			{
				$standardItemIdentity = new StandardItemIdentity();
				$storedItem = $standardItemIdentity->findStoredItemByLink(
					items: $standardItems,
					link: $itemLinkFromRequest,
				);
				if ($storedItem === null)
				{
					$state = (new GroupFavoritesService())->resolveState(
						userId: (int)$this->getCurrentUser()?->getId(),
						link: $itemLinkFromRequest,
					);
					if ($state?->isAccessibleProjectSection())
					{
						$storedItem = $standardItemIdentity->findStoredItemByLink(
							items: $standardItems,
							link: $itemLinkFromRequest,
							state: $state,
						);
					}
				}
				if ($storedItem !== null)
				{
					$itemId = $storedItem['id'];
					$itemLink = $storedItem['link'];
					unset($standardItems[$storedItem['key']]);
				}
			}
			else
			{
				foreach($standardItems as $key => $item)
				{
					if ($item['ID'] == $itemId)
					{
						$itemLink = $item['LINK'];
						unset($standardItems[$key]);
						break;
					}
				}
			}

			$workgroupLink = WorkgroupLink::parse($itemLink);
			if ($workgroupLink['isFeed'] ?? false)
			{
				$this->setGroupToFavorites($workgroupLink['groupId'], 'N');
			}

			if (!empty($standardItems))
			{
				$this->saveStandardItems(SITE_ID, $standardItems);
			}
			else
			{
				$this->deleteStandardItems(SITE_ID);
			}

			return [
				'itemId' => $itemId,
			];
		}
	}

	public function addItemToAllAction()
	{
		if (!$this->isCurrentUserAdmin())
		{
			return null;
		}

		if (isset($_POST['itemInfo']) && is_array($_POST['itemInfo']))
		{
			$itemText = trim($_POST['itemInfo']['text']);
			$itemText = \Bitrix\Main\Text\Emoji::encode($itemText);

			$itemData = array(
				'TEXT' => $itemText,
				'LINK' => $_POST['itemInfo']['link'],
				'ID' => $_POST['itemInfo']['id'],
			);
			if (isset($_POST['itemInfo']['openInNewPage']) && $_POST['itemInfo']['openInNewPage'] == 'Y')
			{
				$itemData['NEW_PAGE'] = 'Y';
			}

			if (!empty($_POST['itemInfo']['counterId']))
				$itemData['COUNTER_ID'] = $_POST['itemInfo']['counterId'];

			$adminOption = Option::get('intranet', 'left_menu_items_to_all_' . SITE_ID, '', SITE_ID);

			if (!empty($adminOption))
			{
				$adminOption = unserialize($adminOption, ['allowed_classes' => false]);
				foreach ($adminOption as $item)
				{
					if ($item['ID'] == $itemData['ID'])
						break;
				}
				$adminOption[] = $itemData;
			}
			else
			{
				$adminOption = array($itemData);
			}

			Option::set('intranet', 'left_menu_items_to_all_' . SITE_ID, serialize($adminOption), false, SITE_ID);
		}
	}

	public function deleteItemFromAllAction()
	{
		if (!$this->isCurrentUserAdmin())
		{
			return null;
		}

		if (!isset($_POST['menu_item_id']))
		{
			return null;
		}

		foreach ([
			'left_menu_items_to_all_' . SITE_ID,
			'left_menu_items_marketplace_' . SITE_ID
			] as $optionName)
		{
			if (($adminOption = Option::get('intranet', $optionName, '', SITE_ID))
				&& !empty($adminOption)
				&& ($adminOption = unserialize($adminOption, ['allowed_classes' => false]))
			)
			{
				foreach ($adminOption as $key => $item)
				{
					if ($item['ID'] == $_POST['menu_item_id'])
					{
						unset($adminOption[$key]);
						if (empty($adminOption))
						{
							\COption::RemoveOption('intranet', $optionName);
						}
						else
						{
							Option::set('intranet', $optionName, serialize($adminOption), SITE_ID);
						}
						break 2;
					}
				}
			}
		}
	}

	public function deleteCustomItemFromAllAction()
	{
		if (!$this->isCurrentUserAdmin())
		{
			return null;
		}

		if (!isset($_POST['menu_item_id']))
		{
			return null;
		}

		$customItems = $this->getMainToolCustomItems();
		if ($this->isMainToolPickerEnabled())
		{
			$state = $this->getMainToolState();
			if ($state['source'] === 'forced' && $state['presetId'] === 'custom')
			{
				$structure = $this->getMainToolStructure('custom');
				$sort = ['version' => 2];
				foreach (['show' => 'shown', 'hide' => 'hidden'] as $status => $visibility)
				{
					$sort[$status] = array_filter(
						$structure[$visibility] ?? [],
						static fn (mixed $item): bool => is_array($item) || (string)$item !== (string)$_POST['menu_item_id'],
					);
				}
				$remainingItems = array_filter(
					$customItems,
					static fn (array $item): bool => (string)$item['ID'] !== (string)$_POST['menu_item_id'],
				);
				if (
					!Preset\PresetAbstract::isForcedSortAllowed($sort, $structure)
					|| !$this->isMainToolCustomItemsAllowed($remainingItems)
				)
				{
					$this->addMainToolError('MAIN_TOOL_FORCED');

					return null;
				}
			}
		}

		$this->deleteCustomItemFromAll($customItems);
	}

	protected function deleteCustomItemFromAll(array $customItems): void
	{
		if (!empty($customItems))
		{
			foreach ($customItems as $key => $item)
			{
				if ($item['ID'] == $_POST['menu_item_id'])
				{
					unset($customItems[$key]);
					if (empty($customItems))
					{
						\COption::RemoveOption('intranet', 'left_menu_custom_preset_items', SITE_ID);
					}
					else
					{
						Option::set('intranet', 'left_menu_custom_preset_items', serialize($customItems), false, SITE_ID);
					}

					break;
				}
			}
		}

		$customItemsSort = Option::get('intranet', 'left_menu_custom_preset_sort', '', SITE_ID);
		if (!empty($customItemsSort))
		{
			$customItemsSort = unserialize($customItemsSort, ['allowed_classes' => false]);
			foreach (array('show', 'hide') as $status)
			{
				foreach ($customItemsSort[$status] as $key=>$itemId)
				{
					if ($itemId == $_POST['menu_item_id'])
					{
						unset($customItemsSort[$status][$key]);
					}
				}
			}

			Option::set('intranet', 'left_menu_custom_preset_sort', serialize($customItemsSort), false, SITE_ID);
		}
	}

	public function saveItemsSortAction()
	{
		if ($this->isMainToolPickerEnabled())
		{
			return $this->saveMainToolItemsSort();
		}

		if (!isset($_POST['items']))
		{
			return null;
		}

		\CUserOptions::SetOption(
			'intranet',
			$this->getItemsSortOptionName(),
			$this->restoreCollaborationMenuSort(
				self::convertItemsSortFromJSToDB($_POST['items'], $_POST['version'] ?? null),
			),
		);

		if (
			isset($_POST['firstItemLink'])
			&& FirstPageUrlPolicy::isAllowedAsFirstPage($_POST['firstItemLink'])
		)
		{
			\CUserOptions::SetOption('intranet', 'left_menu_first_page_' . SITE_ID, $_POST['firstItemLink']);
		}
	}

	private function enablePresetTool($preset): void
	{
		switch ($preset)
		{
			case 'tasks':
				$taskMenu = new Tasks();

				if (!$taskMenu->isEnabled())
				{
					$taskMenu->enableAllSubgroups();
					$taskMenu->enable();
				}

				break;

			case 'crm':
				$crmMenu = new Crm();

				if (!$crmMenu->isEnabled())
				{
					$crmMenu->enableAllSubgroups();
					$crmMenu->enable();
				}

				break;

			case 'sites':
				$sitesMenu = new Sites();

				if (!$sitesMenu->isEnabled())
				{
					$sitesMenu->enable();
				}

				break;

			case 'social':
				$socialMenu = new TeamWork();

				if (!$socialMenu->isEnabled())
				{
					$socialMenu->enableAllSubgroups();
					$socialMenu->enable();
				}

				break;
		}
	}

	protected function isMainToolPickerEnabled(): bool
	{
		return AccessPolicy::isFeatureEnabled();
	}

	protected function createMainToolController(): MainTool
	{
		$controller = new MainTool($this->getRequest());
		$controller->setCurrentUser($this->getCurrentUser() ?? \Bitrix\Main\Engine\CurrentUser::get());

		return $controller;
	}

	public function setPresetAction()
	{
		global $USER;

		if (
			!isset($_POST['preset'])
			|| !in_array($_POST['preset'], array('social', 'crm', 'tasks', 'sites', 'sync'))
			|| !isset($_POST['mode'])
		)
		{
			$this->addError(new Error(Loc::getMessage('INTRANET_LEFT_MENU_PRESET_ERROR')));
			return null;
		}

		if ($this->isMainToolPickerEnabled())
		{
			$controller = $this->createMainToolController();
			$result = $controller->applyLegacyPreset($_POST['preset'], $_POST['mode']);
			$this->addErrors($controller->getErrors());

			return $result;
		}

		$res = [];

		if ($_POST['mode'] == 'global' && $this->isCurrentUserAdmin())
		{
			Option::set('intranet', 'left_menu_preset', $_POST['preset'], false, SITE_ID);

			$event = new Event(
				'intranet',
				'onAfterChangeLeftMenuPreset',
				[
					'SITE_ID' => SITE_ID,
					'VALUE' => $_POST['preset'],
				]
			);
			EventManager::getInstance()->send($event);

			$this->enablePresetTool($_POST['preset']);
		}
		else
		{
			\CUserOptions::SetOption('intranet', 'left_menu_preset_' . SITE_ID, $_POST['preset']);

			\CUserOptions::DeleteOption('intranet', 'left_menu_first_page_' . SITE_ID);
			//	CUserOptions::DeleteOption('intranet', 'left_menu_self_items_'.$siteID);
			//	CUserOptions::DeleteOption('intranet', 'left_menu_standard_items_'.$siteID);
			\CUserOptions::DeleteOption('intranet', $this->getItemsSortOptionName());
		}

		if ($this->isCurrentUserAdmin())
		{
			$this->enablePresetTool($_POST['preset']);
		}

		$firstPageUrl = SITE_DIR . 'stream/';
		switch ($_POST['preset'])
		{
			case 'tasks':
				$firstPageUrl = SITE_DIR . 'company/personal/user/'
					. ($_POST['mode'] == 'global' ? '#USER_ID#' : $USER->GetID()) . '/tasks/'
				;
				break;

			case 'crm':
				if (Loader::includeModule('crm'))
				{
					$firstPageUrl = \Bitrix\Crm\Settings\EntityViewSettings::getDefaultPageUrl();
				}
				break;

			case 'sites':
				$firstPageUrl = SITE_DIR . 'sites/';

				break;

			case 'social':
				$firstPageUrl = (new TeamWork())->getLeftMenuPath();

				break;

			case 'sync':
				$firstPageUrl = SITE_DIR . 'sync/';

				break;
		}

		if ($firstPageUrl)
		{
			if ($_POST['mode'] == 'global' && $this->isCurrentUserAdmin())
			{
				Option::set('intranet', 'left_menu_first_page', $firstPageUrl, false, SITE_ID);
			}
			else
			{
				\CUserOptions::SetOption('intranet', 'left_menu_first_page_' . SITE_ID, $firstPageUrl);
			}

			$res['url'] = str_replace('#USER_ID#', $USER->GetID(), $firstPageUrl);
		}

		if($_POST['mode'] === 'global' && ModuleManager::isModuleInstalled('bitrix24'))
		{
			$_SESSION['B24_SHOW_DEMO_LICENSE_HINT'] = 1;
		}

		$showPresetPopup = Option::get('intranet', 'show_menu_preset_popup', 'N') == 'Y';
		if ($showPresetPopup)
		{
			Option::set('intranet', 'show_menu_preset_popup', 'N');
		}

		return $res;
	}

	public function delaySetPresetAction()
	{
		$showPresetPopup = Option::get('intranet', 'show_menu_preset_popup', 'N') == 'Y';
		if ($showPresetPopup)
		{
			Option::set('intranet', 'show_menu_preset_popup', 'N');
		}
	}

	private static function convertItemsSortFromJSToDB($itemsFromPost, $version = null): array
	{
		$userOption = ['show' => [], 'hide' => []];
		if ($version === null)
		{
			foreach ($userOption as $key => $val)
			{
				if (isset($itemsFromPost[$key]) && is_array($itemsFromPost[$key]))
				{
					$userOption[$key] = $itemsFromPost[$key];
				}
			}
		}
		else
		{
			$convert = function($res, &$itemsPointer) use (&$convert) {
				foreach ($res as $item)
				{
					if (is_string($item))
					{
						$itemsPointer[] = $item;
					}
					else if (is_array($item) && isset($item['group_id']))
					{
						if (!empty($item['items']))
						{
							$itemsPointer[$item['group_id']] = [];
							$convert($item['items'], $itemsPointer[$item['group_id']]);
						}
						else
						{
							$itemsPointer[] = $item;
						}
					}
				}
			};
			$convert($itemsFromPost['show'] ?? [], $userOption['show']);
			$convert($itemsFromPost['hide'] ?? [], $userOption['hide']);
			unset($convert);
			$userOption['version'] = $version;
		}
		return $userOption;
	}

	private function restoreCollaborationMenuSort(array $sort, bool $customPreset = false): array
	{
		if (!isset($sort['version']))
		{
			return $sort;
		}

		$postedIds = [];
		$postedGroupIds = [];
		$collectIds = static function (array $items) use (&$collectIds, &$postedIds, &$postedGroupIds): void {
			foreach ($items as $key => $item)
			{
				if (is_array($item))
				{
					if (isset($item['group_id']))
					{
						$postedGroupIds[$item['group_id']] = true;

						continue;
					}
					if (is_string($key))
					{
						$postedGroupIds[$key] = true;
					}
					$collectIds($item);
				}
				elseif (is_string($item))
				{
					$postedIds[$item] = true;
				}
			}
		};
		$collectIds($sort['show']);
		$collectIds($sort['hide']);
		if (!isset($postedIds['menu_teamwork']))
		{
			return $sort;
		}

		// A flattened system group can be saved after the feature has been disabled.
		$preset = Preset\Manager::getPreset();
		$previous = $customPreset
			? unserialize(Option::get('intranet', 'left_menu_custom_preset_sort', '', SITE_ID), ['allowed_classes' => false])
			: \CUserOptions::GetOption('intranet', $this->getItemsSortOptionName(), [])
		;
		if (is_array($previous) && !isset($previous['version']) && !$customPreset)
		{
			$previous = Preset\PresetAbstract::oldToNewStructure($previous, $preset->getStructure());
		}
		$teamworkItems = self::findCollaborationMenuGroup(is_array($previous) ? $previous : [])
			?? self::findCollaborationMenuGroup($preset->getStructure())
			?? []
		;
		$removePostedItems = static function (array $items) use (&$removePostedItems, $postedIds, $postedGroupIds): array {
			$result = [];
			foreach ($items as $key => $item)
			{
				if (is_array($item))
				{
					if (isset($item['group_id']))
					{
						if (!isset($postedGroupIds[$item['group_id']]))
						{
							$result[] = $item;
						}
					}
					elseif (!isset($postedGroupIds[$key]))
					{
						$result[$key] = $removePostedItems($item);
					}
				}
				elseif (!isset($postedIds[$item]))
				{
					$result[] = $item;
				}
			}

			return $result;
		};
		$teamworkItems = $removePostedItems($teamworkItems);
		$restore = static function (array $items) use (&$restore, $teamworkItems): array {
			$result = [];
			foreach ($items as $key => $item)
			{
				if ($item === 'menu_teamwork')
				{
					$result['menu_teamwork'] = $teamworkItems;
				}
				elseif (is_array($item) && !isset($item['group_id']))
				{
					$result[$key] = $restore($item);
				}
				else
				{
					$result[] = $item;
				}
			}

			return $result;
		};
		$sort['show'] = $restore($sort['show']);
		$sort['hide'] = $restore($sort['hide']);

		return $sort;
	}

	private static function findCollaborationMenuGroup(array $items): ?array
	{
		if (isset($items['menu_teamwork']) && is_array($items['menu_teamwork']))
		{
			return $items['menu_teamwork'];
		}
		foreach ($items as $item)
		{
			if (is_array($item))
			{
				$group = self::findCollaborationMenuGroup($item);
				if ($group !== null)
				{
					return $group;
				}
			}
		}

		return null;
	}

	public function saveCustomPresetAction()
	{
		if ($this->isMainToolPickerEnabled())
		{
			return $this->saveMainToolCustomPreset();
		}

		if (!$this->isCurrentUserAdmin())
		{
			return null;
		}

		if (isset($_POST['userApply']) && $_POST['userApply'] === 'currentUser')
		{
			\CUserOptions::DeleteOptionsByName('intranet', 'left_menu_sorted_items_' . SITE_ID);
			\CUserOptions::DeleteOptionsByName('intranet', 'left_menu_preset_' . SITE_ID);
			\CUserOptions::DeleteOptionsByName('intranet', 'left_menu_first_page_' . SITE_ID);
			FirstPage::getInstance()->clearCacheForAll();
		}

		$restoredSort = null;
		if (isset($_POST['itemsSort']))
		{
			$restoredSort = $this->restoreCollaborationMenuSort(
				self::convertItemsSortFromJSToDB($_POST['itemsSort'], $_POST['version'] ?? null),
				true,
			);
			Option::set(
				'intranet',
				'left_menu_custom_preset_sort',
				serialize($restoredSort),
				SITE_ID,
			);
		}

		if (isset($_POST['customItems']))
		{
			$customItems = $_POST['customItems'];
			if (is_array($customItems) && is_array($restoredSort))
			{
				$customItems = $this->restoreCollaborationMenuCustomItems($customItems, $restoredSort);
			}
			Option::set('intranet', 'left_menu_custom_preset_items', serialize($customItems), false, SITE_ID);
		}

		Option::set('intranet', 'left_menu_preset', 'custom', false, SITE_ID);
		if (
			isset($_POST['firstItemLink'])
			&& is_string($_POST['firstItemLink'])
			&& FirstPageUrlPolicy::isAllowedAsFirstPage($_POST['firstItemLink'])
		)
		{
			$firstPageUrl = (new PortalFirstPageUrlNormalizer())
				->normalize($_POST['firstItemLink'])
			;

			Option::set('intranet', 'left_menu_first_page', $firstPageUrl, false, SITE_ID);
		}
	}

	private function restoreCollaborationMenuCustomItems(array $customItems, array $sort): array
	{
		$teamworkItems = self::findCollaborationMenuGroup($sort);
		if ($teamworkItems === null)
		{
			return $customItems;
		}

		$teamworkIds = [];
		array_walk_recursive($teamworkItems, static function ($id) use (&$teamworkIds): void {
			if (is_string($id))
			{
				$teamworkIds[$id] = true;
			}
		});
		$postedIds = [];
		foreach ($customItems as $item)
		{
			if (is_array($item) && is_string($item['ID'] ?? null))
			{
				$postedIds[$item['ID']] = true;
			}
		}
		$serializedPreviousItems = Option::get('intranet', 'left_menu_custom_preset_items', '', SITE_ID);
		if ($serializedPreviousItems === '')
		{
			return $customItems;
		}
		$previousItems = unserialize($serializedPreviousItems, ['allowed_classes' => false]);
		if (!is_array($previousItems))
		{
			return $customItems;
		}
		foreach ($previousItems as $item)
		{
			$id = is_array($item) ? ($item['ID'] ?? null) : null;
			if (is_string($id) && isset($teamworkIds[$id]) && !isset($postedIds[$id]))
			{
				$customItems[] = $item;
			}
		}

		return $customItems;
	}

	public function setFirstPageAction()
	{
		if (!isset($_POST['firstPageUrl']))
		{
			return null;
		}
		if ($this->isMainToolPickerEnabled())
		{
			$state = $this->getMainToolState();
			if (!$this->isMainToolFirstPageAllowed($_POST['firstPageUrl'], $state))
			{
				return null;
			}
			$this->saveMainToolPersonalOption('left_menu_first_page_' . SITE_ID, $_POST['firstPageUrl']);

			return null;
		}

		if ($this->rejectInvalidFirstPage($_POST['firstPageUrl']))
		{
			return null;
		}

		\CUserOptions::SetOption('intranet', 'left_menu_first_page_' . SITE_ID, $_POST['firstPageUrl']);
	}

	private function rejectInvalidFirstPage(mixed $url): bool
	{
		if (!FirstPageUrlPolicy::isAllowedAsFirstPage($url))
		{
			$this->addMainToolError('MAIN_TOOL_INVALID_ARGUMENT');

			return true;
		}

		return false;
	}

	protected function getMainToolState(): array
	{
		return Preset\Manager::getEffectiveState(SITE_ID);
	}

	protected function getMainToolStructure(string $presetId): array
	{
		return Preset\Manager::getPreset($presetId !== '' ? $presetId : 'social', SITE_ID)->getStructure();
	}

	protected function addMainToolError(string $code): void
	{
		Loc::loadMessages(__DIR__ . '/maintool.php');
		$this->addError(new Error((string)Loc::getMessage($code), $code));
	}

	protected function isMainToolFirstPageAllowed(mixed $url, array $state): bool
	{
		if (
			!is_string($url)
			|| !str_starts_with($url, '/')
			|| str_starts_with($url, '//')
			|| str_contains($url, '\\')
			|| strlen($url) > 2048
			|| preg_match('/[\x00-\x20\x7f]/', $url) === 1
		)
		{
			$this->addMainToolError('MAIN_TOOL_INVALID_ARGUMENT');

			return false;
		}
		if ($this->rejectInvalidFirstPage($url))
		{
			return false;
		}
		if ($state['source'] === 'forced')
		{
			$userId = (string)$this->getCurrentUser()->getId();
			$expected = str_replace('#USER_ID#', $userId, $state['firstPage'] ?? '');
			if (str_replace('#USER_ID#', $userId, $url) !== $expected)
			{
				$this->addMainToolError('MAIN_TOOL_FORCED');

				return false;
			}
		}

		return true;
	}

	protected function saveMainToolPersonalOption(string $name, mixed $value): bool
	{
		if (!\CUserOptions::SetOption('intranet', $name, $value))
		{
			$this->addMainToolError('MAIN_TOOL_SAVE_FAILED');

			return false;
		}
		Preset\Manager::clearCache(SITE_ID);

		return true;
	}

	protected function saveMainToolItemsSort(): ?array
	{
		if (!isset($_POST['items']))
		{
			return null;
		}
		if (!is_array($_POST['items']))
		{
			$this->addMainToolError('MAIN_TOOL_INVALID_ARGUMENT');

			return null;
		}
		$data = self::convertItemsSortFromJSToDB($_POST['items'], $_POST['version'] ?? null);
		$state = $this->getMainToolState();
		if (
			$state['source'] === 'forced'
			&& !Preset\PresetAbstract::isForcedSortAllowed(
				$data,
				$this->getMainToolStructure($state['presetId'] ?? 'social'),
			)
		)
		{
			$this->addMainToolError('MAIN_TOOL_FORCED');

			return null;
		}
		if (isset($_POST['firstItemLink']) && !is_string($_POST['firstItemLink']))
		{
			$this->addMainToolError('MAIN_TOOL_INVALID_ARGUMENT');

			return null;
		}
		$saveFirstPage = isset($_POST['firstItemLink'])
			&& FirstPageUrlPolicy::isAllowedAsFirstPage($_POST['firstItemLink'])
		;
		if ($saveFirstPage && !$this->isMainToolFirstPageAllowed($_POST['firstItemLink'], $state))
		{
			return null;
		}
		if (!$this->saveMainToolPersonalOption($this->getItemsSortOptionName(), $data))
		{
			return null;
		}
		if ($saveFirstPage)
		{
			$this->saveMainToolPersonalOption('left_menu_first_page_' . SITE_ID, $_POST['firstItemLink']);
		}

		return null;
	}

	protected function saveMainToolCustomPreset(): ?array
	{
		if (!$this->isCurrentUserAdmin())
		{
			return null;
		}
		$state = $this->getMainToolState();
		$sort = null;
		if (isset($_POST['itemsSort']))
		{
			if (!is_array($_POST['itemsSort']))
			{
				$this->addMainToolError('MAIN_TOOL_INVALID_ARGUMENT');

				return null;
			}
			$sort = self::convertItemsSortFromJSToDB($_POST['itemsSort'], $_POST['version'] ?? null);
		}
		if (
			$state['source'] === 'forced'
			&& (
				$state['presetId'] !== 'custom'
				|| ($sort !== null && !Preset\PresetAbstract::isForcedSortAllowed(
					$sort,
					$this->getMainToolStructure('custom'),
				))
			)
		)
		{
			$this->addMainToolError('MAIN_TOOL_FORCED');

			return null;
		}
		if (isset($_POST['firstItemLink']) && !is_string($_POST['firstItemLink']))
		{
			$this->addMainToolError('MAIN_TOOL_INVALID_ARGUMENT');

			return null;
		}
		$saveFirstPage = isset($_POST['firstItemLink'])
			&& FirstPageUrlPolicy::isAllowedAsFirstPage($_POST['firstItemLink'])
		;
		if ($saveFirstPage && !$this->isMainToolFirstPageAllowed($_POST['firstItemLink'], $state))
		{
			return null;
		}

		if (
			$state['source'] === 'forced'
			&& isset($_POST['customItems'])
			&& !$this->isMainToolCustomItemsAllowed($_POST['customItems'])
		)
		{
			$this->addMainToolError('MAIN_TOOL_FORCED');

			return null;
		}

		if (isset($_POST['userApply']) && $_POST['userApply'] === 'currentUser')
		{
			foreach (['left_menu_sorted_items_', 'left_menu_preset_', 'left_menu_first_page_'] as $prefix)
			{
				if (!\CUserOptions::DeleteOptionsByName('intranet', $prefix . SITE_ID))
				{
					$this->addMainToolError('MAIN_TOOL_SAVE_FAILED');

					return null;
				}
				Preset\Manager::clearCache(SITE_ID);
			}
			FirstPage::getInstance()->clearCacheForAll();
		}
		if ($sort !== null)
		{
			Option::set('intranet', 'left_menu_custom_preset_sort', serialize($sort), SITE_ID);
			Preset\Manager::clearCache(SITE_ID);
		}
		if (isset($_POST['customItems']))
		{
			Option::set('intranet', 'left_menu_custom_preset_items', serialize($_POST['customItems']), SITE_ID);
			Preset\Manager::clearCache(SITE_ID);
		}
		Option::set('intranet', 'left_menu_preset', 'custom', SITE_ID);
		Preset\Manager::clearCache(SITE_ID);
		if ($saveFirstPage)
		{
			$firstPageUrl = $_POST['firstItemLink'];
			if (preg_match('~company/personal/user/\d+/tasks/$~i', $firstPageUrl))
			{
				$firstPageUrl = preg_replace('~/user/\d+/tasks/$~i', '/user/#USER_ID#/tasks/', $firstPageUrl);
			}
			Option::set('intranet', 'left_menu_first_page', $firstPageUrl, SITE_ID);
			Preset\Manager::clearCache(SITE_ID);
		}

		return null;
	}

	protected function isMainToolCustomItemsAllowed(mixed $items): bool
	{
		if (!is_array($items))
		{
			return false;
		}
		$saved = array_column($this->getMainToolCustomItems(), 'LINK', 'ID');
		$links = array_column($items, 'LINK', 'ID');
		if (
			array_key_exists('main_page', $saved) !== array_key_exists('main_page', $links)
			|| ($saved['main_page'] ?? null) !== ($links['main_page'] ?? null)
		)
		{
			return false;
		}
		$structure = $this->getMainToolStructure('custom')['shown'] ?? [];
		if (reset($structure) === 'main_page')
		{
			unset($structure[array_key_first($structure)]);
		}
		while ($structure !== [])
		{
			$key = array_key_first($structure);
			$value = $structure[$key];
			$id = is_array($value) ? $key : $value;
			if (
				array_key_exists($id, $saved) !== array_key_exists($id, $links)
				|| ($saved[$id] ?? null) !== ($links[$id] ?? null)
			)
			{
				return false;
			}
			$structure = is_array($value) ? $value : [];
		}

		return true;
	}

	protected function getMainToolCustomItems(): array
	{
		$items = unserialize(
			Option::get('intranet', 'left_menu_custom_preset_items', '', SITE_ID),
			['allowed_classes' => false],
		);

		return is_array($items) ? $items : [];
	}

	protected function resetMainToolMenu(string $siteId): ?array
	{
		if (!$this->isCurrentUserAdmin())
		{
			return null;
		}
		foreach ([
			'left_menu_first_page_' . $siteId,
			'left_menu_preset_' . $siteId,
			'left_menu_self_items_' . $siteId,
			'left_menu_preset_' . $siteId,
			'left_menu_standard_items_' . $siteId,
			'left_menu_sorted_items_' . $siteId,
			'left_menu_groups_' . $siteId,
			'left_menu_collapsed',
			'left_menu_collapsed:online',
		] as $name)
		{
			if (!\CUserOptions::DeleteOptionsByName('intranet', $name))
			{
				$this->addMainToolError('MAIN_TOOL_SAVE_FAILED');

				return null;
			}
			Preset\Manager::clearCache($siteId);
		}

		\COption::RemoveOption('intranet', 'left_menu_preset', $siteId);
		Preset\Manager::clearCache($siteId);
		(new Repository())->setForced($siteId, false);
		\COption::RemoveOption('intranet', 'show_menu_preset_popup');
		Preset\Manager::clearCache($siteId);
		\COption::RemoveOption('intranet', 'left_menu_items_to_all_' . $siteId);
		Preset\Manager::clearCache($siteId);
		\COption::RemoveOption('intranet', 'left_menu_custom_preset_items', $siteId);
		Preset\Manager::clearCache($siteId);
		\COption::RemoveOption('intranet', 'left_menu_custom_preset_sort', $siteId);
		Preset\Manager::clearCache($siteId);

		return null;
	}

	public function clearCacheAction()
	{
		//This action only for a composite cache
	}

	public function setDefaultMenuAction()
	{
		if ($this->isMainToolPickerEnabled())
		{
			$state = $this->getMainToolState();
			if ($state['source'] === 'forced')
			{
				$this->addMainToolError('MAIN_TOOL_FORCED');

				return null;
			}
		}

		\CUserOptions::DeleteOption('intranet', 'left_menu_first_page_' . SITE_ID);
		\CUserOptions::DeleteOption('intranet', 'left_menu_self_items_' . SITE_ID);
		$this->deleteStandardItems(SITE_ID);
		\CUserOptions::DeleteOption('intranet', $this->getItemsSortOptionName());
		\CUserOptions::DeleteOption('intranet', 'left_menu_groups_' . SITE_ID);

		if (Option::get('intranet', 'left_menu_preset', '', SITE_ID) === 'custom')
		{
			\CUserOptions::DeleteOptionsByName('intranet', 'left_menu_preset_' . SITE_ID);
		}

		if ($this->isMainToolPickerEnabled())
		{
			Preset\Manager::clearCache(SITE_ID);
		}
	}

	public function collapseMenuGroupAction($id)
	{
		$groups = \CUserOptions::GetOption('intranet', 'left_menu_groups_' . SITE_ID);
		$groups = is_array($groups) ? $groups : [];
		$groups[$id] = 'collapsed';
		\CUserOptions::SetOption('intranet', 'left_menu_groups_' . SITE_ID, $groups);
	}

	public function expandMenuGroupAction($id)
	{
		$groups = \CUserOptions::GetOption('intranet', 'left_menu_groups_' . SITE_ID);
		$groups = is_array($groups) ? $groups : [];
		$groups[$id] = 'expanded';
		\CUserOptions::SetOption('intranet', 'left_menu_groups_' . SITE_ID, $groups);
	}

	public function collapseMenuAction(string $context = '')
	{
		if ($context === 'online')
		{
			\CUserOptions::SetOption('intranet', 'left_menu_collapsed:online', 'Y');
		}
		else
		{
			\CUserOptions::SetOption('intranet', 'left_menu_collapsed', 'Y');
		}
	}

	public function expandMenuAction(string $context = '')
	{
		if ($context === 'online')
		{
			\CUserOptions::SetOption('intranet', 'left_menu_collapsed:online', 'N');
		}
		else
		{
			\CUserOptions::SetOption('intranet', 'left_menu_collapsed', 'N');
		}
	}

	public function setGroupFilterAction()
	{
		if (isset($_POST['filter']) && in_array($_POST['filter'], array('all', 'extranet', 'favorites')))
		{
			\CUserOptions::SetOption('intranet', 'left_menu_group_filter_' . SITE_ID, $_POST['filter']);
		}
	}

	public function addToFavoritesAction()
	{
		if (isset($_POST['groupId']) && intval($_POST['groupId']))
		{
			$this->setGroupToFavorites(
				intval($_POST['groupId']),
				'Y'
			);
		}
	}

	public function removeFromFavoritesAction()
	{
		if (isset($_POST['groupId']) && intval($_POST['groupId']))
		{
			$this->setGroupToFavorites(
				intval($_POST['groupId']),
				'N'
			);
		}
	}

	public function getGroupsAction(): array
	{
		$filter = \CUserOptions::getOption('intranet', 'left_menu_group_filter_' . SITE_ID, 'all');;

		return [
			'filter' => $filter,
			'groups' => static::getMyGroups(),
		];
	}

	public static function getMyGroups(): array
	{
		$userId = $GLOBALS['USER']->getId();
		$cacheTtl = defined('BX_COMP_MANAGED_CACHE') ? 2592000 : 600;
		$cacheId = 'bitrix24_group_list_v2' . $userId . '_' . SITE_ID . '_' . isModuleInstalled('extranet');
		$cacheDir = '/bx/bitrix24_group_list/' . substr(md5($userId), 0, 2) . '/' . $userId . '/';

		$cache = new \CPHPCache();

		if ($cache->initCache($cacheTtl, $cacheId, $cacheDir))
		{
			return $cache->getVars();
		}

		$cache->startDataCache();
		if (defined('BX_COMP_MANAGED_CACHE'))
		{
			$GLOBALS['CACHE_MANAGER']->startTagCache($cacheDir);
			$GLOBALS['CACHE_MANAGER']->registerTag('sonet_user2group_U' . $userId);
			$GLOBALS['CACHE_MANAGER']->registerTag('sonet_group');
			$GLOBALS['CACHE_MANAGER']->registerTag('sonet_group_favorites_U' . $userId);
			$GLOBALS['CACHE_MANAGER']->endTagCache();
		}

		$groups = [];
		if (!Loader::includeModule('socialnetwork') || $userId <= 0)
		{
			return $groups;
		}

		$extranetSiteId = \COption::getOptionString('extranet', 'extranet_site');
		$getGroups = function($siteId, $limit, $ids = []) use ($userId, $extranetSiteId) {
			$groups = WorkgroupTable::getList([
				'filter' => [
					'=ACTIVE' => 'Y',
					'!=CLOSED' => 'Y',
					'=GS.SITE_ID' => $siteId,
					'<=UG.ROLE' => UserToGroupTable::ROLE_USER,
					'!=TYPE' => \Bitrix\Socialnetwork\Item\Workgroup\Type::Collab->value,
				] + (empty($ids) ? [] : ['!@ID' => $ids]),
				'order' => [
					'NAME' => 'ASC',
				],
				'select' => ['ID', 'NAME'],
				'count_total' => false,
				'offset' => 0,
				'limit' => $limit,
				'runtime' => [
					new ReferenceField(
						'UG',
						UserToGroupTable::getEntity(),
						['=ref.GROUP_ID' => 'this.ID', '=ref.USER_ID' => new SqlExpression($userId)],
						['join_type' => 'INNER'],
					),
					new ReferenceField(
						'GS',
						WorkgroupSiteTable::getEntity(),
						['=ref.GROUP_ID' => 'this.ID'],
						['join_type' => 'INNER'],
					),
				],
			]);

			$result = [];
			while ($group = $groups->fetch())
			{
				$result[$group['ID']] = [
					'id' => $group['ID'],
					'title' => $group['NAME'],
					'url' => SITE_DIR . 'workgroups/group/' . $group["ID"] . '/',
					'extranet' => $siteId === $extranetSiteId,
					'favorite' => false,
				];
			}

			return $result;
		};

		$getFavorites = function($siteId, $limit, $ids = []) use ($userId, $extranetSiteId) {
			$groups = WorkgroupTable::getList([
				'filter' => [
					'=GF.USER_ID' => $userId,
					'=GS.SITE_ID' => $siteId,
					[
						'LOGIC' => 'OR',
						'=VISIBLE' => 'Y',
						'<=UG.ROLE' => UserToGroupTable::ROLE_USER,
					],
				] + (empty($ids) ? [] : ['!@ID' => $ids]),
				'order' => [
					'NAME' => 'ASC',
				],
				'select' => ['ID', 'NAME'],
				'count_total' => false,
				'offset' => 0,
				'limit' => $limit,
				'runtime' => [
					new ReferenceField(
						'UG',
						UserToGroupTable::getEntity(),
						['=ref.GROUP_ID' => 'this.ID', '=ref.USER_ID' => new SqlExpression($userId)],
						['join_type' => 'LEFT'],
					),
					new ReferenceField(
						'GS',
						WorkgroupSiteTable::getEntity(),
						['=ref.GROUP_ID' => 'this.ID'],
						['join_type' => 'INNER'],
					),
					new ReferenceField(
						'GF',
						WorkgroupFavoritesTable::getEntity(),
						['=ref.GROUP_ID' => 'this.ID'],
						['join_type' => 'INNER']
					),
				  ],
			  ]);

			$result = [];
			while ($group = $groups->fetch())
			{
				$result[$group['ID']] = [
					'id' => $group['ID'],
					'title' => $group['NAME'],
					'url' => SITE_DIR . 'workgroups/group/' . $group["ID"] . '/',
					'extranet' => $siteId === $extranetSiteId,
					'favorite' => true,
				];
			}

			return $result;
		};

		// Main\ModuleManager::isModuleInstalled($module_id);

		$extranetGroups = isModuleInstalled('extranet') ? $getGroups($extranetSiteId, 150) : [];
		$intranetGroups = $getGroups(SITE_ID, 150, array_keys($extranetGroups));
		$favoriteExtranetGroups = isModuleInstalled('extranet') ? $getFavorites($extranetSiteId, 100) : [];
		$favoriteIntranetGroups = $getFavorites(SITE_ID, 100, array_keys($favoriteExtranetGroups));

		$groups = array_replace(
			$extranetGroups,
			$intranetGroups,
			$favoriteExtranetGroups,
			$favoriteIntranetGroups
		);

		Collection::sortByColumn($groups, 'title');
		$cache->endDataCache($groups);

		return $groups;
	}

	public function resetAllAction()
	{
		if (!$this->isCurrentUserAdmin())
		{
			return null;
		}
		$sites = \CSite::getList();

		while ($site = $sites->Fetch())
		{
			$this->resetAction($site['SITE_ID']);
		}
	}

	public function resetAction(string $siteId)
	{
		if ($this->isMainToolPickerEnabled())
		{
			return $this->resetMainToolMenu($siteId);
		}

		if (!$this->isCurrentUserAdmin())
		{
			return null;
		}

		\CUserOptions::DeleteOptionsByName('intranet', 'left_menu_first_page_' . $siteId);
		\CUserOptions::DeleteOptionsByName('intranet', 'left_menu_preset_' . $siteId);
		\CUserOptions::DeleteOptionsByName('intranet', 'left_menu_self_items_' . $siteId);
		\CUserOptions::DeleteOptionsByName('intranet', 'left_menu_preset_' . $siteId);
		(new GroupFavoritesService())->deleteAllFavoritesForSite($siteId);
		\CUserOptions::DeleteOptionsByName('intranet', 'left_menu_sorted_items_' . $siteId);
		\CUserOptions::DeleteOptionsByName('intranet', 'left_menu_groups_' . $siteId);
		\CUserOptions::DeleteOptionsByName('intranet', 'left_menu_collapsed');
		\CUserOptions::DeleteOptionsByName('intranet', 'left_menu_collapsed:online');

		\COption::RemoveOption('intranet', 'left_menu_preset');
		\COption::RemoveOption('intranet', 'show_menu_preset_popup');

		\COption::RemoveOption('intranet', 'left_menu_items_to_all_' . $siteId);
		\COption::RemoveOption('intranet', 'left_menu_custom_preset_items', $siteId);
		\COption::RemoveOption('intranet', 'left_menu_custom_preset_sort', $siteId);
	}

	private function saveStandardItems(string $siteId, array $items): void
	{
		(new GroupFavoritesService())->saveFavorites(
			$siteId,
			(int)$this->getCurrentUser()?->getId(),
			$items,
		);
	}

	private function deleteStandardItems(string $siteId): void
	{
		(new GroupFavoritesService())->deleteFavoritesForSite(
			$siteId,
			(int)$this->getCurrentUser()?->getId(),
		);
	}
}
