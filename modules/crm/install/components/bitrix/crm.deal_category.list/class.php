<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Crm\Restriction\RestrictionManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions\ConfigurationCallSite;
use Bitrix\Crm\Service\UserPermissions\ConfigurationScope;
use Bitrix\Crm\V2\Internal\Service\Category\Deal\DealCategoryPageService;
use Bitrix\Crm\V2\Infrastructure\Component\Category\Deal\Grid\DealCategoryGrid;
use Bitrix\Main\Context;
use Bitrix\Main\Grid\Component\ComponentParams;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__DIR__ . '/component.php');

if (!Loader::includeModule('crm'))
{
	return;
}

final class CrmDealCategoryListComponent extends CBitrixComponent
{
	private const GRID_ID = 'CRM_DEAL_CATEGORY_LIST';

	private ?DealCategoryGrid $grid = null;
	private ?DealCategoryPageService $pageService = null;

	public function executeComponent(): void
	{
		$canManageFunnels = Container::getInstance()->getUserPermissions()->configuration()->canManageFunnels(
			ConfigurationCallSite::DealCategoryListAjax,
			ConfigurationScope::ofEntityType(CCrmOwnerType::Deal),
		);
		if (!$canManageFunnels)
		{
			ShowError(Loc::getMessage('CRM_PERMISSION_DENIED'));

			return;
		}

		$openEditId = $this->parseOpenEditId(Context::getCurrent()->getRequest()->getQuery('open_edit'));
		$restriction = RestrictionManager::getDealCategoryLimitRestriction();
		$this->arResult['GRID_ID'] = self::GRID_ID;
		$this->arResult['grid'] = $this->prepareGridParams($openEditId);
		$this->arResult['CATEGORY_COUNT'] = $this->getPageService()->getTotalCount();
		$this->arResult['QUANTITY_LIMIT'] = $restriction->getQuantityLimit();
		$this->arResult['INFO_HELPER_ID'] = $restriction->getInfoHelperId();

		if ($openEditId !== null)
		{
			$this->arResult['OPEN_EDIT'] = $openEditId;
		}

		$this->includeComponentTemplate();
	}

	private function prepareGridParams(?int $openEditId = null): array
	{
		$grid = $this->getGrid();
		$pageService = $this->getPageService();

		$grid->initPagination($pageService->getTotalCount());
		$grid->processRequest();

		$pagination = $grid->getPagination();
		if ($pagination === null)
		{
			throw new LogicException('Deal category grid pagination is not initialized.');
		}

		$pageSize = max(1, $pagination->getLimit());
		$pagination->setPageSize($pageSize);
		if ($openEditId !== null)
		{
			$targetOffset = $pageService->getPageOffsetForCategory(
				$openEditId,
				$grid->getOrmOrder(),
				$pageSize,
			);
			if ($targetOffset !== null)
			{
				$pagination->setCurrentPage(intdiv($targetOffset, $pageSize) + 1);
			}
		}

		$page = $pageService->getPage(
			$grid->getOrmOrder(),
			$pagination->getOffset(),
			$pageSize,
		);

		if ($pagination->getOffset() !== $page->offset)
		{
			$pagination->setCurrentPage(intdiv($page->offset, $pageSize) + 1);
		}

		$pagination->setRecordCount($page->totalCount);
		$grid->setRawRows($page->rows);

		return ComponentParams::get($grid, [
			'USE_CHECKBOX_LIST_FOR_SETTINGS_POPUP' => \Bitrix\Main\ModuleManager::isModuleInstalled('ui'),
		]);
	}

	private function parseOpenEditId(mixed $value): ?int
	{
		if (!is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) !== 1)
		{
			return null;
		}

		$maxValue = (string)PHP_INT_MAX;
		if (strlen($value) > strlen($maxValue)
			|| (strlen($value) === strlen($maxValue) && strcmp($value, $maxValue) > 0)
		)
		{
			return null;
		}

		return (int)$value;
	}

	private function getPageService(): DealCategoryPageService
	{
		$this->pageService ??= new DealCategoryPageService();

		return $this->pageService;
	}

	private function getGrid(): DealCategoryGrid
	{
		$this->grid ??= new DealCategoryGrid(
			new Settings([
				'ID' => self::GRID_ID,
			]),
		);

		return $this->grid;
	}
}
