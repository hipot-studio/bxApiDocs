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

class CrmItemDeadlinesComponent extends Bitrix\Crm\Component\ItemList
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

		if (!$this->factory->isListViewSupported(Router::LIST_VIEW_DEADLINES))
		{
			$fallbackUrl = $this->router->getFallbackItemListUrl($this->entityTypeId, $this->getCategoryId());
			if ($fallbackUrl !== null)
			{
				LocalRedirect($fallbackUrl->getUri());
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
			'c_sub_section' => Dictionary::SUB_SECTION_DEADLINES,
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
			Service\Router::LIST_VIEW_DEADLINES => true,
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
		return $this->router->getDeadlinesUrl($this->entityTypeId, $categoryId);
	}

	protected function getListViewType(): string
	{
		return Router::LIST_VIEW_DEADLINES;
	}

	protected function isAutomationButtonAvailable(): bool
	{
		return false;
	}

	protected function configureAnalyticsEventBuilder(\Bitrix\Crm\Integration\Analytics\Builder\AbstractBuilder $builder): void
	{
		parent::configureAnalyticsEventBuilder($builder);

		if (!$this->isEmbedded())
		{
			$builder->setSubSection(\Bitrix\Crm\Integration\Analytics\Dictionary::SUB_SECTION_DEADLINES);
		}
	}
}
