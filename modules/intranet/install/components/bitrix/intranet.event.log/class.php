<?php

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bitrix24\Component\EventList;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Intranet\EventLogManager;
use Bitrix\Main\UI\Filter\DateType;

class EventLogComponent extends CBitrixComponent implements Controllerable
{
	private string $gridId = 'INTRANET_EVENT_LOG_GRID';
	private string $filterId = 'INTRANET_EVENT_LOG_FILTER';
	private ?array $eventTypesFilter = null;

	private static function hasAccess(): bool
	{
		$currentUser = CurrentUser::get();

		return (
			(Loader::includeModule('intranet') && $currentUser->isAdmin())
			|| (Loader::includeModule('bitrix24') && $currentUser->CanDoOperation('bitrix24_config'))
		);
	}

	private function getGridColumns(): array
	{
		static $result = null;

		if ($result === null) {
			$result = [
				[
					'id' => 'ID',
					'name' => Loc::getMessage('INTRANET_EVENT_LOG_COLUMN_ID'),
					'default' => true,
				],
				[
					'id' => 'EVENT_NAME',
					'fieldId' => 'AUDIT_TYPE_ID',
					'name' => Loc::getMessage('INTRANET_EVENT_LOG_COLUMN_AUDIT_TYPE'),
					'default' => true,
				],
				[
					'id' => 'USER_NAME',
					'name' => Loc::getMessage('INTRANET_EVENT_LOG_COLUMN_USER'),
					'default' => true,
				],
				[
					'id' => 'REMOTE_ADDR',
					'name' => Loc::getMessage('INTRANET_EVENT_LOG_COLUMN_IP'),
					'default' => true,
				],
				[
					'id' => 'TIMESTAMP_X',
					'name' => Loc::getMessage('INTRANET_EVENT_LOG_COLUMN_TIMESTAMP'),
					'default' => true,
				],
				[
					'id' => 'DESCRIPTION',
					'name' => Loc::getMessage('INTRANET_EVENT_LOG_COLUMN_DESCRIPTION'),
					'default' => true,
				]
			];
		}

		return $result;
	}

	private function getFilterFields(): array
	{
		return [
			[
				'id' => 'EVENT_NAME',
				'name' => Loc::getMessage('INTRANET_EVENT_LOG_COLUMN_AUDIT_TYPE'),
				'default' => true,
				'type' => 'list',
				'items' => array_intersect_key(EventLogManager::getInstance()->getEventTypes(), array_flip($this->getEventTypesFilter())),
			],
			[
				'id' => 'TIMESTAMP',
				'name' => Loc::getMessage('INTRANET_EVENT_LOG_COLUMN_TIMESTAMP'),
				'default' => true,
				'type' => 'date',
				'exclude' => [
					DateType::NEXT_MONTH,
					DateType::NEXT_WEEK,
					DateType::NEXT_DAYS,
					DateType::TOMORROW,
					// events are not stored for more than 7 days
					DateType::LAST_WEEK,
					DateType::LAST_7_DAYS,
					DateType::LAST_30_DAYS,
					DateType::LAST_60_DAYS,
					DateType::LAST_90_DAYS,
					DateType::LAST_MONTH,
					DateType::CURRENT_WEEK,
					DateType::CURRENT_MONTH,
					DateType::CURRENT_QUARTER,
					DateType::MONTH,
					DateType::QUARTER,
					DateType::YEAR,
				]
			],
			[
				'id' => 'USER_ID',
				'name' => Loc::getMessage('INTRANET_EVENT_LOG_COLUMN_USER'),
				'default' => true,
				'type' => 'entity_selector',
				'params' => [
					'dialogOptions' => [
						'height' => 240,
						'context' => 'filter',
						'entities' => [
							[
								'id' => 'user',
								'options' => [
									'inviteEmployeeLink' => false
								],
							],
							[
								'id' => 'department',
							],
						],
					],
				],
			],
			[
				'id' => 'IP',
				'name' => Loc::getMessage('INTRANET_EVENT_LOG_COLUMN_IP'),
				'default' => true,
				'type' => 'text',
			],
		];
	}

	public function getEventTypesFilter(): array
	{
		if ($this->eventTypesFilter === null)
		{
			$eventTypesFilter = [];

			if (Loader::includeModule('bitrix24'))
			{
				$eventTypesFilter = EventList::prepareFilter();
			}
			else
			{
				$eventTypes = [];

				foreach (EventLogManager::getInstance()->getEventLogGetAuditHandlers() as $eventLogGetAuditHandler)
				{
					$filter = $eventLogGetAuditHandler->GetFilter();
					$filterIds = array_keys($filter);
					$filterId = array_intersect($filterIds, $this->arParams['FILTER']);

					if ($filterId)
					{
						if(isset($filter['IBLOCK']))
						{
							//iblock has more complex structure because logs are kept for individual blocks
							$filterId = $filterIds;
						}

						$eventTypes = array_merge($eventTypes, $eventLogGetAuditHandler->GetFilterSQL($filterId));
					}
				}

				foreach ($eventTypes as $value)
				{
					if (array_key_exists('AUDIT_TYPE_ID', $value))
					{
						$eventTypesFilter[] = $value['AUDIT_TYPE_ID'];
					}
				}
			}

			$this->eventTypesFilter = $eventTypesFilter;
		}

		return $this->eventTypesFilter;
	}

	private function getFilter($gridFilter): array
	{
		$filter = [];

		$availableEventTypes = $this->getEventTypesFilter();

		if (!empty($availableEventTypes))
		{
			if (!empty($gridFilter['EVENT_NAME']) && in_array($gridFilter['EVENT_NAME'], $availableEventTypes))
			{
				$filter['=AUDIT_TYPE_ID'] = $gridFilter['EVENT_NAME'];
			}
			else
			{
				$filter['@AUDIT_TYPE_ID'] = $availableEventTypes;
			}
		}
		else
		{
			if (!empty($gridFilter['EVENT_NAME']))
			{
				$filter['=AUDIT_TYPE_ID'] = $gridFilter['EVENT_NAME'];
			}
		}

		if (isset($gridFilter['TIMESTAMP_from']) && $gridFilter['TIMESTAMP_from'])
		{
			$filter['>=TIMESTAMP_X'] = $gridFilter['TIMESTAMP_from'];
		}

		if (isset($gridFilter['TIMESTAMP_to']) && $gridFilter['TIMESTAMP_to'])
		{
			$filter['<=TIMESTAMP_X'] = $gridFilter['TIMESTAMP_to'];
		}

		if (isset($gridFilter['USER_ID']))
		{
			$filter['=USER_ID'] = $gridFilter['USER_ID'];
		}

		if (isset($gridFilter['IP']))
		{
			$filter['=REMOTE_ADDR'] = $gridFilter['IP'];
		}

		return $filter;
	}

	private function getDefaultGridHeaders(): array
	{
		$result = [];
		$gridColumns = $this->getGridColumns();

		foreach ($gridColumns as $column)
		{
			if (!empty($column['default']))
			{
				$result[] = $column['id'];
			}
		}

		return $result;
	}

	private function getSelect($gridOptions): array
	{
		$result = ['ID'];
		$gridColumns = $gridOptions->getVisibleColumns();

		if (empty($gridColumns))
		{
			$gridColumns = $this->getDefaultGridHeaders();
		}

		foreach ($gridColumns as $column)
		{
			$result[] = match ($column) {
				'EVENT_NAME' => 'AUDIT_TYPE_ID',
				'USER_NAME' => 'USER_ID',
				default => $column,
			};
		}

		return $result;
	}

	private function fillUserNames(array $rows, array $userIdList): array
	{
		$userList = \Bitrix\Main\UserTable::getList([
			'select' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'SECOND_NAME'],
			'filter' => [
				'@ID' => $userIdList,
			]
		])->fetchCollection();

		$userNames = [];
		$nameFormat = \CSite::GetNameFormat();

		foreach ($rows as $key => $event)
		{
			if (isset($event['data']['USER_ID']))
			{
				$userId = $event['data']['USER_ID'];

				if (array_key_exists($userId, $userNames))
				{
					$rows[$key]['data']['USER_NAME'] = $userNames[$userId];
				}
				else
				{
					$name = \CUser::FormatName($nameFormat, $userList->getByPrimary($userId), true);

					if (!empty($this->arParams['USER_PATH']))
					{
						$name = '<a href="' . htmlspecialcharsbx(str_replace('#user_id#', $userId, $this->arParams['USER_PATH'])) . '">' . $name . '</a>';
					}

					$rows[$key]['data']['USER_NAME'] = $name;
					$userNames[$userId] = $name;
				}
			}
			else
			{
				$rows[$key]['data']['USER_NAME'] = '';
			}
		}

		return $rows;
	}

	public function configureActions(): array
	{
		return [];
	}

	public function executeComponent(): void
	{
		if (!static::hasAccess())
		{
			ShowError('ACCESS_DENIED');
			return;
		}

		$this->arResult['GRID_ID'] = $this->gridId;
		$this->arResult['FILTER_ID'] = $this->filterId;
		$this->arResult['GRID_COLUMNS'] = $this->getGridColumns();

		$gridOptions = new \Bitrix\Main\Grid\Options($this->gridId);
		$filterOptions = new \Bitrix\Main\UI\Filter\Options($this->filterId);
		$this->arResult['FILTER'] = $this->getFilterFields();
		$gridFilter = $filterOptions->getFilter($this->arResult['FILTER']);

		$navParams = $gridOptions->getNavParams();
		$pageSize = $navParams['nPageSize'];

		$nav = new \Bitrix\Main\UI\PageNavigation('page');

		$nav->allowAllRecords(false)
			->setPageSize($pageSize)
			->initFromUri();

		$query = new \Bitrix\Main\Entity\Query(\Bitrix\Main\EventLog\Internal\EventLogTable::getEntity());
		$query->setSelect($this->getSelect($gridOptions));
		$query->setFilter($this->getFilter($gridFilter));
		$query->setOrder(['ID' => 'DESC']);
		$query->countTotal(true);
		$query->setOffset($nav->getOffset());
		$query->setLimit($nav->getLimit());
		$res = $query->exec();
		$nav->setRecordCount($res->getCount());

		$eventTypes = EventLogManager::getInstance()->getEventTypes();
		$rows = [];
		$userIdList = [];

		while ($event = $res->fetch())
		{
			$data = $event;

			if (isset($event['USER_ID']) && !in_array($event['USER_ID'], $userIdList))
			{
				$userIdList[] = $event['USER_ID'];
			}

			if (isset($event['AUDIT_TYPE_ID']))
			{
				$data['EVENT_NAME'] = array_key_exists($event['AUDIT_TYPE_ID'], $eventTypes) && $eventTypes[$event['AUDIT_TYPE_ID']]
					? $eventTypes[$event['AUDIT_TYPE_ID']]
					: $event['AUDIT_TYPE_ID'];
			}

			$rows[] = [
				'id' => $event['ID'],
				'data' => $data,
			];
		}

		if (!empty($userIdList) && !empty($rows))
		{
			$rows = $this->fillUserNames($rows, $userIdList);
		}

		$this->arResult['GRID_ROWS'] = $rows;
		$this->arResult['NAV_OBJECT'] = $nav;

		$this->includeComponentTemplate();
	}
}