<?php

namespace Bitrix\Crm\Kanban\Entity\Deadlines;

use Bitrix\Crm\Item;
use Bitrix\Crm\Item\Quote as QuoteItem;
use Bitrix\Crm\Kanban\Entity\Deadlines\Stagefilters\StageFilter;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Router;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\Date;

class DeadlinesStageManager
{
	public const FILTER_FIELD_CONTAINS_STAGE = 'CURRENT_DEADLINES_STAGE_ID';

	public const STAGE_OVERDUE = 'OVERDUE';
	public const STAGE_TODAY = 'TODAY';
	public const STAGE_THIS_WEEK = 'THIS_WEEK';
	public const STAGE_NEXT_WEEK = 'NEXT_WEEK';
	public const STAGE_LATER = 'LATER';
	public const STAGE_COMPLETED = 'COMPLETED';

	private DatePeriods $datePeriods;

	private StageFilter $stageFilter;

	private int $entityTypeId;

	private string $dateFieldName;

	public static function dateFieldByEntityType(int $entityTypeId): string
	{
		switch ($entityTypeId)
		{
			case \CCrmOwnerType::SmartInvoice:
				return Item::FIELD_NAME_CLOSE_DATE;
			case \CCrmOwnerType::Quote:
				return QuoteItem::FIELD_NAME_ACTUAL_DATE;
			default:
				throw new ArgumentException("Entity id: $entityTypeId doesn't supported in deadlines view");
		}
	}

	/**
	 * The answer comes from Service\Factory, but the date field below is still resolved by a local
	 * switch: a new type allowed into the deadlines view has to be added to dateFieldByEntityType()
	 * in the same change, otherwise it gets here and hits the ArgumentException.
	 */
	public static function isEntitySupportDeadlines(int $entityTypeId): bool
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);

		return $factory !== null && $factory->isListViewSupported(Router::LIST_VIEW_DEADLINES);
	}

	public function __construct(int $entityTypeId)
	{
		$this->entityTypeId = $entityTypeId;
		$this->dateFieldName = self::dateFieldByEntityType($this->entityTypeId);
		$this->datePeriods = new DatePeriods();
		$this->stageFilter = Stagefilters\Factory::make($entityTypeId);
	}

	/**
	 * @param array $parameters It is parameters same as of used by getListEx or getList methods of crm entities.
	 * @return array
	 */
	public function prepareItemsFilter(array $parameters): array
	{
		$filter = $parameters['filter'] ?? [];
		$stageId = $filter[self::FILTER_FIELD_CONTAINS_STAGE] ?? null;
		unset($filter[self::FILTER_FIELD_CONTAINS_STAGE]);

		if (empty($stageId)) {
			return $parameters;
		}

		$parameters['columnId'] = $stageId;
		$parameters['filter'] = $this->applyStageFilter($stageId, $filter);
		return $parameters;
	}

	public function prepareItemsResult(\CDBResult $dbItemsResult, ?string $columnId): array
	{
		$items = [];
		while ($item = $dbItemsResult->Fetch())
		{
			if (empty($columnId))
			{
				$date = isset($item[$this->dateFieldName]) ? new Date($item[$this->dateFieldName]) : null;
				$item[self::FILTER_FIELD_CONTAINS_STAGE] = $this->datePeriods->stageByDate($date);
			}
			else
			{
				$item[self::FILTER_FIELD_CONTAINS_STAGE] = $columnId;
			}
			$items[$item['ID']] = $item;
		}
		return $items;
	}

	public function calculateDateByStage(string $stage): ?Date
	{
		return $this->datePeriods->calculateDateByStage($stage);
	}

	public function checkIsStageAllowed(string $stage): bool
	{
		$allowedStages = [
			DeadlinesStageManager::STAGE_TODAY,
			DeadlinesStageManager::STAGE_THIS_WEEK,
			DeadlinesStageManager::STAGE_NEXT_WEEK,
			DeadlinesStageManager::STAGE_LATER,
		];

		return in_array($stage, $allowedStages);
	}

	public function stagesList(): array
	{
		return [
			[
				'STATUS_ID' => self::STAGE_OVERDUE,
				'NAME' => Loc::getMessage('CRM_DEADLINES_STAGE_OVERDUE'),
				'COLOR' => '#ff5752',
				'BLOCKED_INCOMING_MOVING' => true,
			],
			[
				'STATUS_ID' => self::STAGE_TODAY,
				'NAME' => Loc::getMessage('CRM_DEADLINES_STAGE_TODAY'),
				'COLOR' => '#7bd500',
			],
			[
				'STATUS_ID' => self::STAGE_THIS_WEEK,
				'NAME' => Loc::getMessage('CRM_DEADLINES_STAGE_THIS_WEEK'),
				'COLOR' => '#2fc6f6',
			],
			[
				'STATUS_ID' => self::STAGE_NEXT_WEEK,
				'NAME' => Loc::getMessage('CRM_DEADLINES_STAGE_NEXT_WEEK'),
				'COLOR' => '#55d0e0',
			],
			[
				'STATUS_ID' =>self::STAGE_LATER,
				'NAME' => Loc::getMessage('CRM_DEADLINES_STAGE_LATER'),
				'COLOR' => '#3373bb',
			],
		];
	}

	public function applyStageFilter(string $stage, array $filter): array
	{
		return $this->stageFilter->applyFilter($stage, $filter, $this->dateFieldName);
	}

	/**
	 * Make associative array filled by default values specified for current entity
	 *
	 * @param array $data
	 * @param string $deadlineStage
	 * @return array
	 */
	public function fillDeadlinesDefaultValues(array $data, string $deadlineStage): array
	{
		$deadlineSage = $data['DEADLINE_STAGE'];

		if (!$this->checkIsStageAllowed($deadlineSage)) {
			return $data;
		}

		$field = $this->dateFieldName;
		if (empty($data[$field]))
		{
			$defaultDate = $this->datePeriods->calculateDateByStage($deadlineStage);
			$data[$field] = $defaultDate;
		}
		return $data;
	}

}
