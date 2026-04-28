<?php

use Bitrix\Crm\Component\Base;
use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\RepeatSaleSandbox;
use Bitrix\Crm\RepeatSale\Sandbox\Entity\RepeatSaleSandboxTable;
use Bitrix\Crm\RepeatSale\Sandbox\Grid\SandboxGrid;
use Bitrix\Crm\RepeatSale\Segment\Controller\RepeatSaleSegmentController;
use Bitrix\Crm\Router\ResponseHelper;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CrmRepeatSaleSandbox extends Base
{
	private const GRID_ID = 'crm-repeat-sale-sandbox-grid';

	public function executeComponent(): void
	{
		if (!Feature::enabled(RepeatSaleSandbox::class))
		{
			ResponseHelper::showPageNotFound();
		}

		$this->init();

		$this->includeComponentTemplate();
	}

	protected function init(): void
	{
		parent::init();

		$this->arResult['title'] = Loc::getMessage('CRM_REPEAT_SALE_SANDBOX_TITLE');
		$this->arResult['data'] = $this->getData();
		$this->arResult['grid'] = $this->getGrid();
	}

	private function getData(): array
	{
		return [
			'segments' => $this->getSegments(),
			'gridId' => self::GRID_ID,
		];
	}

	private function getSegments(): array
	{
		$collection = RepeatSaleSegmentController::getInstance()->getList([
			'select' => ['TITLE', 'PROMPT'],
			'order' => ['TITLE' => 'ASC'],
		]);

		$result = [];
		foreach ($collection as $item)
		{
			$result[] = [
				'id' => $item->getId(),
				'title' => $item->getTitle(),
				'prompt' => $item->getPrompt(),
			];
		}

		return $result;
	}

	private function getGrid(): SandboxGrid
	{
		$settings = new Settings([
			'ID' => self::GRID_ID,
		]);

		$grid = new SandboxGrid($settings, $this->userPermissions);
		$totalCount = RepeatSaleSandboxTable::getCount($grid->getOrmFilter() ?? []);
		$grid->initPagination($totalCount);
		$grid->processRequest();
		$grid->setRawRows(RepeatSaleSandboxTable::getList($grid->getOrmParams()));

		return $grid;
	}
}
