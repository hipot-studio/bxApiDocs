<?php

use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\Restriction\RestrictionManager;
use Bitrix\Crm\Service;
use Bitrix\Crm\Service\Router;
use Bitrix\Crm\UI\Tools\ToolBar;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED!==true)
{
	die();
}

\Bitrix\Main\Loader::includeModule('crm');

class CrmItemActivitiesComponent extends Bitrix\Crm\Component\ItemList
{
	public function executeComponent()
	{
		Service\Container::getInstance()->getLocalization()->loadKanbanMessages();

		$this->init();

		if ($this->getErrors())
		{
			$this->includeComponentTemplate();

			return;
		}

		if (
			$this->factory instanceof \Bitrix\Crm\Service\Factory\Dynamic
			&& !$this->factory->isCountersEnabled()
		)
		{
			$categoryId = $this->getCategoryId();
			$listUrl = $this->router->getItemListUrl($this->entityTypeId, $categoryId);
			if ($listUrl !== null)
			{
				LocalRedirect($listUrl->addParams(['modeNotAvailable' => 'Y'])->getUri());
			}

			return;
		}

		$restriction = RestrictionManager::getItemListRestriction($this->entityTypeId);
		if (!$restriction->hasPermission())
		{
			$this->arResult['restriction'] = $restriction;
			$this->arResult['entityName'] = \CCrmOwnerType::ResolveName($this->entityTypeId);
			$this->includeComponentTemplate('restrictions');

			return;
		}

		$this->arResult['entityTypeName'] = CCrmOwnerType::ResolveName($this->entityTypeId);
		$this->arResult['categoryId'] = $this->getCategoryId();
		$this->arResult['entityTypeDescription'] = $this->factory->getEntityDescription();
		$this->arResult['isCountersEnabled'] = $this->factory->getCountersSettings()->isCountersEnabled();
		$this->arResult['pathToMerge'] = $this->router->getEntityMergeUrl($this->entityTypeId);

		$section = Dictionary::getAnalyticsEntityType($this->entityTypeId) . '_section';
		$this->arResult['analytics'] = [
			'c_section' => $section,
			'c_sub_section' => Dictionary::SUB_SECTION_ACTIVITIES,
		];

		$this->includeComponentTemplate();
	}

	protected function getToolbarSettingsItems(): array
	{
		return array_merge([ToolBar::getKanbanSettings()], parent::getToolbarSettingsItems());
	}

	protected function getToolbarViews(): array
	{
		$views = parent::getToolbarViews();

		$activeByViewType = [
			Service\Router::LIST_VIEW_KANBAN => false,
			Service\Router::LIST_VIEW_LIST => false,
			Service\Router::LIST_VIEW_ACTIVITY => true,
		];
		foreach ($activeByViewType as $viewType => $isActive)
		{
			if (isset($views[$viewType]))
			{
				$views[$viewType]['isActive'] = $isActive;
			}
		}

		return $views;
	}

	protected function getListUrl(int $categoryId = null): \Bitrix\Main\Web\Uri
	{
		return $this->router->getActivityUrl($this->entityTypeId, $categoryId);
	}

	protected function isAutomationButtonAvailable(): bool
	{
		return false;
	}

	protected function getToolbarCategories(array $categories): array
	{
		$allItemsItem = [
			'id' => 'toolbar-category-all',
			'categoryId' => null,
			'text' => htmlspecialcharsbx(\Bitrix\Main\Localization\Loc::getMessage('CRM_TYPE_TOOLBAR_ALL_ITEMS')),
			'href' => $this->getListUrl(null)->getUri(),
		];

		$menu = array_merge([$allItemsItem], parent::getToolbarCategories($categories));

		return $menu;
	}

	protected function getListViewType(): string
	{
		return Router::LIST_VIEW_ACTIVITY;
	}

	protected function configureAnalyticsEventBuilder(\Bitrix\Crm\Integration\Analytics\Builder\AbstractBuilder $builder): void
	{
		parent::configureAnalyticsEventBuilder($builder);

		if (!$this->isEmbedded())
		{
			$builder->setSubSection(\Bitrix\Crm\Integration\Analytics\Dictionary::SUB_SECTION_ACTIVITIES);
		}
	}
}
