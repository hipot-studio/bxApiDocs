<?php

namespace Bitrix\Crm\Kanban\Entity;

use Bitrix\Crm\Item;
use Bitrix\Crm\Item\SmartInvoice as SmartInvoiceItem;
use Bitrix\Crm\Kanban\Entity\Deadlines\DeadlinesStageManager;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class SmartInvoiceDeadlines extends SmartInvoice
{
	private DeadlinesStageManager $deadlinesManager;

	private string $dateFieldName;

	/** @var array<int, bool> */
	private array $canDisplaySumsByCategory = [];

	public function __construct()
	{
		parent::__construct();
		$this->deadlinesManager = new DeadlinesStageManager(\CCrmOwnerType::SmartInvoice);
		$this->dateFieldName = DeadlinesStageManager::dateFieldByEntityType(\CCrmOwnerType::SmartInvoice);
	}

	public function getStageFieldName(): string
	{
		return DeadlinesStageManager::FILTER_FIELD_CONTAINS_STAGE;
	}

	public function getDbStageFieldName(): string
	{
		return parent::getStageFieldName();
	}

	public function getStagesList(): array
	{
		return $this->deadlinesManager->stagesList();
	}

	/**
	 * Aggregates every period with its own query, as the stages are virtual and cannot be grouped by.
	 *
	 * Opts out of TotalSumsCache: its key does not tell the view modes apart, and the bounds of the periods
	 * move with the current date.
	 */
	protected function getDataToCalculateTotalSums(string $fieldSum, array $filter, array $runtime): array
	{
		$data = [];

		$this->factory->createItem();
		$dataClass = $this->factory->getDataClass();
		foreach ($this->deadlinesManager->stagesList() as $stage)
		{
			$stageId = $stage['STATUS_ID'];
			$queryParameters = [
				'filter' => $this->prepareTotalSumsFilter($this->deadlinesManager->applyStageFilter($stageId, $filter)),
				'select' => [
					new ExpressionField($fieldSum, 'SUM(%s)', Item::FIELD_NAME_OPPORTUNITY_ACCOUNT),
					new ExpressionField('CNT', 'COUNT(1)'),
				],
			];
			if (!empty($runtime))
			{
				$queryParameters['runtime'] = $runtime;
			}

			$row = $dataClass::getList($queryParameters)->fetch();
			$data[] = [
				$this->getStageFieldName() => $stageId,
				$fieldSum => (float)($row[$fieldSum] ?? 0),
				'CNT' => (int)($row['CNT'] ?? 0),
			];
		}

		return $data;
	}

	/**
	 * A period gathers invoices of every stage, so its sum is shown only when sums are shown in all of them.
	 */
	public function havePermissionToDisplayColumnSum(string $stageId): bool
	{
		return $this->canDisplaySums();
	}

	public function prepareItemCommonFields(array $item): array
	{
		$item = parent::prepareItemCommonFields($item);
		if (!$this->canDisplaySums())
		{
			$item['PRICE'] = 0.0;
			$item['PRICE_FORMATTED'] = $this->getHiddenPriceFormattedText($item['CURRENCY_FORMAT']);
			$item['OPPORTUNITY_VALUE'] = 0.0;
			$item[Item::FIELD_NAME_OPPORTUNITY_ACCOUNT] = null;
			$item['OPPORTUNITY']['SUM'] = null;
		}

		return $item;
	}

	/**
	 * Every stage of the category counts, the ones excluded by the filter too: the rule does not depend on it.
	 */
	private function canDisplaySums(): bool
	{
		$categoryId = (int)$this->categoryId;
		if (!isset($this->canDisplaySumsByCategory[$categoryId]))
		{
			$canDisplay = true;
			foreach ($this->factory->getStages($categoryId) as $stage)
			{
				$canDisplay = $this->userPermissions->kanban()->canReadKanbanSumInStage(
					$this->getTypeId(),
					$categoryId,
					$stage->getStatusId(),
				);
				if (!$canDisplay)
				{
					break;
				}
			}
			$this->canDisplaySumsByCategory[$categoryId] = $canDisplay;
		}

		return $this->canDisplaySumsByCategory[$categoryId];
	}

	public function getItems(array $parameters): \CDBResult
	{
		$parameters = $this->deadlinesManager->prepareItemsFilter($parameters);
		$columnId = $parameters['columnId'] ?? '';
		unset($parameters['columnId']);
		$rawResult = parent::getItems($parameters);

		$items = $this->deadlinesManager->prepareItemsResult($rawResult, $columnId);

		$dbResult = new \CDBResult();
		$dbResult->InitFromArray($items);
		return $dbResult;
	}

	public function updateItemStage(int $id, string $stageId, array $newStateParams, array $stages): Result
	{
		if (!$this->deadlinesManager->checkIsStageAllowed($stageId))
		{
			return (new Result())->addError(
				new Error(Loc::getMessage('CRM_KANBAN_DEADLINE_VIEW_MODE_MOVE_ITEM_TO_COLUMN_BLOCKED'))
			);
		}

		/** @var $item SmartInvoiceItem */
		$item = $this->factory->getItem($id);
		if (!$item)
		{
			return (new Result())->addError(new Error(Loc::getMessage('CRM_TYPE_ITEM_NOT_FOUND')));
		}

		$item->set($this->dateFieldName, $this->deadlinesManager->calculateDateByStage($stageId));

		return
			$this->factory
				->getUpdateOperation($item)
				->launch();
	}

	public function isRecurringSupported(): bool
	{
		return false;
	}

	public function isExclusionSupported(): bool
	{
		return false;
	}

	public function isActivityCountersFilterSupported(): bool
	{
		return $this->factory->isCountersEnabled();
	}

	public function getRequiredFieldsByStages(array $stages): array
	{
		return [];
	}
}
