<?php

namespace Bitrix\BIConnector\Integration\Superset\Repository;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardGroupBindingTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardGroupTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTagTable;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\BIConnector\Configuration\DashboardTariffConfigurator;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter;
use Bitrix\BIConnector\Superset\Filter\Provider\DashboardDataProvider;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\Entity\Base;
use Bitrix\Main\DB\SqlExpression;

final class DashboardGroupRepository extends DashboardRepository
{
	public const TYPE_DASHBOARD = 'D';
	public const TYPE_GROUP = 'G';

	/**
	 * Load data from DB using additional data from proxy
	 *
	 * @param array $ormParams
	 * @param bool $needLoadProxyData
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getList(
		array $ormParams,
		bool $needLoadProxyData = false,
		?DashboardGroupListOptions $listOptions = null,
		?int $accessUserId = null
	): array
	{
		$listOptions ??= DashboardGroupListOptions::createDefault();

		$accessController = $accessUserId === null
			? AccessController::getCurrent()
			: new AccessController($accessUserId)
		;

		$visibleDashboardIds = array_key_exists('DASHBOARD_ID', $ormParams['filter'] ?? [])
			? array_map('intval', (array)$ormParams['filter']['DASHBOARD_ID'])
			: null;
		$query = $this->prepareUnionQuery($ormParams, true, $listOptions);

		$items = $query->exec();

		$rows = [];
		$dashboardIds = [];
		$groupIds = [];
		while ($item = $items->fetch())
		{
			$itemId = $item['ID'];
			$entityType = $item['ENTITY_TYPE'];
			$rows["{$itemId}_{$entityType}"] = [];
			if ($entityType === self::TYPE_GROUP)
			{
				$groupIds[] = $itemId;
			}
			else
			{
				$dashboardIds[] = $itemId;
			}
		}

		if (!empty($dashboardIds))
		{
			$dashboards = $this->getDashboardRows($dashboardIds, $needLoadProxyData, $accessController);
			foreach ($dashboards as $dashboard)
			{
				$rows[$dashboard['ID'] . '_' . self::TYPE_DASHBOARD] = $dashboard;
			}
		}

		if (!empty($groupIds))
		{
			$groups = $this->getGroupRows($groupIds, $visibleDashboardIds, $accessController);
			foreach ($groups as $group)
			{
				$rows[$group['ID'] . '_' . self::TYPE_GROUP] = $group;
			}
		}

		return array_values($rows);
	}

	public function getCount(array $ormParams, ?DashboardGroupListOptions $listOptions = null): int
	{
		$listOptions ??= DashboardGroupListOptions::createDefault();
		unset($ormParams['order']);
		$query =
			$this
				->prepareUnionQuery($ormParams, false, $listOptions)
				->countTotal(true)
		;

		return $query->exec()->getCount();
	}

	public function getDashboardRow(
		Dashboard $dashboard,
		array $additionalOptions = [],
	): array
	{
		$row = $dashboard->toArray();
		$urlService = new UrlParameter\Service($dashboard->getOrmObject());
		$row['URL_PARAMS'] = [];
		foreach ($urlService->getUrlParameters() as $parameter)
		{
			$row['URL_PARAMS'][] = $parameter->title();
		}
		$row['DETAIL_URL'] = $urlService->getEmbeddedUrl();
		if (!$dashboard->getOrmObject()->isTagsFilled())
		{
			$dashboard->getOrmObject()->fillTags();
		}
		$row['TAGS'] = $dashboard->getOrmObject()->getTags()->collectValues();

		if (!$dashboard->getOrmObject()->isGroupsFilled())
		{
			$dashboard->getOrmObject()->fillGroups();
		}
		$row['GROUPS'] = $dashboard->getOrmObject()->getGroups()->collectValues();

		if (!$dashboard->getOrmObject()->isScopeFilled())
		{
			$dashboard->getOrmObject()->fillScope();
		}
		$row['SCOPE'] = $dashboard->getOrmObject()->getScope()->getScopeCodeList();
		sort($row['SCOPE']);

		$appId = $dashboard->getAppId();
		$row['IS_TARIFF_RESTRICTED'] = !empty($appId) && !DashboardTariffConfigurator::isAvailableDashboard($appId);
		$row['IS_AVAILABLE_DASHBOARD'] = $dashboard->isAvailableDashboard();
		if ($dashboard->getOrmObject()->getSource())
		{
			$urlSourceService = new UrlParameter\Service($dashboard->getOrmObject()->getSource());
			$row['SOURCE_DETAIL_URL'] = $urlSourceService->getEmbeddedUrl();
		}
		else
		{
			$row['SOURCE_DETAIL_URL'] = '';
		}
		$row['ENTITY_TYPE'] = self::TYPE_DASHBOARD;

		$dashboardsInTopMenu = \CUserOptions::getOption('biconnector', 'top_menu_dashboards', []);
		$dashboardsInTopMenu = array_flip($dashboardsInTopMenu);
		$pinnedDashboards = \CUserOptions::getOption('biconnector', 'grid_pinned_dashboards', []);
		$pinnedDashboards = array_flip($pinnedDashboards);

		$row['IS_IN_TOP_MENU'] = isset($dashboardsInTopMenu[$dashboard->getId()]);
		$row['IS_PINNED'] = isset($pinnedDashboards[$dashboard->getId()]);

		return array_merge($row, $additionalOptions);
	}

	private function getDashboardRows(array $ids, bool $needLoadProxyData, AccessController $accessController): array
	{
		$dashboardOrmParams = [
			'filter' => ['ID' => $ids],
		];

		$allowedIds = $accessController->getAllowedDashboardValue(
			ActionDictionary::ACTION_BIC_DASHBOARD_VIEW,
		);
		$allowedIds = array_map('intval', $allowedIds ?? []);

		$dashboardRows = [];
		$dashboards = parent::getList($dashboardOrmParams, $needLoadProxyData);
		foreach ($dashboards as $dashboard)
		{
			$dashboardRows[] = $this->getDashboardRow(
				$dashboard,
				[
					'IS_ACCESS_ALLOWED' => in_array($dashboard->getId(), $allowedIds, true),
				],
			);
		}

		return $dashboardRows;
	}

	private function getGroupRows(array $ids, ?array $dashboardIds, AccessController $accessController): array
	{
		$allowedIds = $accessController->getAllowedGroupValue(
			ActionDictionary::ACTION_BIC_DASHBOARD_VIEW,
		);
		$allowedIds = array_map('intval', $allowedIds ?? []);

		$groupOrmParams = [
			'filter' => ['ID' => $ids],
			'select' => ['*', 'SCOPE'],
		];
		$dashboardCounts = $this->getDashboardCountsByGroupIds($ids, $dashboardIds);

		$groupRows = [];
		$emptyCommonColumns = [
			"EXTERNAL_ID",
			"STATUS",
			"URL",
			"DETAIL_URL",
			"EDIT_URL",
			"SOURCE_ID",
			"SOURCE_TITLE",
			"APP_ID",
			"CREATED_BY_ID",
			"IS_TARIFF_RESTRICTED",
			"IS_AVAILABLE_DASHBOARD",
			"SOURCE_DETAIL_URL",
		];

		$groups = SupersetDashboardGroupTable::getList($groupOrmParams)->fetchCollection();
		foreach ($groups as $group)
		{
			$row = [
				"ID" => $group->getId(),
				"TITLE" => $group->getName(),
				"DATE_CREATE" => $group->getDateCreate(),
				"DATE_MODIFY" => $group->getDateModify(),
				"SCOPE" => $group->getScope()->getScopeCodeList(),
				"TYPE" => $group->getType(),
				"TAGS" => [],
				"IS_ACCESS_ALLOWED" => in_array($group->getId(), $allowedIds, true),
				"URL_PARAMS" => [],
				"GROUPS" => [],
				'ENTITY_TYPE' => self::TYPE_GROUP,
				'COUNT_DASHBOARDS' => (int)($dashboardCounts[$group->getId()] ?? 0),
			];

			sort($row['SCOPE']);
			foreach ($emptyCommonColumns as $column)
			{
				$row[$column] = '';
			}

			$groupRows[] = $row;
		}

		return $groupRows;
	}

	private function getDashboardCountsByGroupIds(array $groupIds, ?array $dashboardIds): array
	{
		$groupIds = array_values(array_unique(array_map('intval', $groupIds)));
		if (empty($groupIds) || $dashboardIds === [])
		{
			return [];
		}

		$query = SupersetDashboardGroupBindingTable::query()
			->setSelect(['GROUP_ID', 'DASHBOARD_COUNT'])
			->registerRuntimeField(new ExpressionField('DASHBOARD_COUNT', 'COUNT(1)'))
			->whereIn('GROUP_ID', $groupIds)
			->addGroup('GROUP_ID')
		;
		if ($dashboardIds !== null)
		{
			$query->whereIn('DASHBOARD_ID', $dashboardIds);
		}

		$counts = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$counts[(int)$row['GROUP_ID']] = (int)$row['DASHBOARD_COUNT'];
		}

		return $counts;
	}

	/**
	 * Method `GetById` is not allowed
	 *
	 * @param int $dashboardId
	 * @param bool $needLoadProxyData
	 *
	 * @return Dashboard|null
	 */
	public function getById(int $dashboardId, bool $needLoadProxyData = false): ?Dashboard
	{
		return null;
	}

	private function prepareUnionQuery(array $ormParams, bool $applyUnionOrder = true, ?DashboardGroupListOptions $listOptions = null): Query
	{
		$listOptions ??= DashboardGroupListOptions::createDefault();

		$queryDashboard = SupersetDashboardTable::query()
			->addSelect(new ExpressionField('ENTITY_TYPE', "'" . self::TYPE_DASHBOARD . "'"))
			->addSelect('ID')
		;

		$emptyUnionFilterGroupFields = [
			'SOURCE_ID',
			'STATUS',
			'TAGS',
			'GROUPS',
			'URL_PARAMS',
			'CREATED_BY_ID',
		];

		$queryGroup = SupersetDashboardGroupTable::query()
			->addSelect(new ExpressionField('ENTITY_TYPE', "'" . self::TYPE_GROUP . "'"))
			->addSelect('ID')
			->registerRuntimeField(new ExpressionField('TITLE', 'NAME'))
		;

		$hasPinnedOrder = $applyUnionOrder && $this->registerPinnedOrderFields($queryDashboard, $queryGroup, $listOptions);

		foreach ($emptyUnionFilterGroupFields as $unionField)
		{
			$queryGroup->registerRuntimeField(new ExpressionField($unionField, 'NULL'));
		}

		if (!empty($ormParams['runtime']) && is_array($ormParams['runtime']))
		{
			foreach ($ormParams['runtime'] as $runtimeField)
			{
				if ($runtimeField->getName() === 'IS_PINNED')
				{
					$queryGroup->registerRuntimeField(new ExpressionField($runtimeField->getName(), 0));
					$queryGroup->addSelect($runtimeField->getName());
					$queryDashboard->registerRuntimeField($runtimeField);
					$queryDashboard->addSelect($runtimeField->getName());

					break;
				}
			}
		}

		if (!empty($ormParams['order']) && is_array($ormParams['order']))
		{
			foreach (array_keys($ormParams['order']) as $fieldCode)
			{
				if ($fieldCode !== 'ENTITY_TYPE' && $fieldCode !== 'ID')
				{
					$queryGroup->addSelect($fieldCode);
					$queryDashboard->addSelect($fieldCode);
				}
			}

			$innerOrder = $ormParams['order'];
			unset($innerOrder['ENTITY_TYPE']);
			if (!empty($innerOrder))
			{
				$queryGroup->setOrder($innerOrder);
				$queryDashboard->setOrder($innerOrder);
			}
		}

		if (!empty($ormParams['filter']))
		{
			$dashboardFilter = $ormParams['filter'];
			if (array_key_exists('DASHBOARD_ID', $dashboardFilter))
			{
				$dashboardFilter['=ID'] = $dashboardFilter['DASHBOARD_ID'];
			}

			unset($dashboardFilter['DASHBOARD_ID']);
			unset($dashboardFilter['GROUP_ID']);

			$groupFilter = $ormParams['filter'];
			if (array_key_exists('GROUP_ID', $groupFilter))
			{
				$groupFilter['=ID'] = $groupFilter['GROUP_ID'];
			}
			unset($groupFilter['DASHBOARD_ID']);
			unset($groupFilter['GROUP_ID']);

			$queryDashboard->setFilter($dashboardFilter);

			$this->applyDashboardListOptions(
				$queryDashboard,
				$listOptions,
			);

			$isScopeFilter = array_key_exists('=SCOPE.SCOPE_CODE', $ormParams['filter']);
			$isShareBanFilter =
				array_key_exists(DashboardDataProvider::SHARE_BANNED_ORM_KEY, $ormParams['filter'])
				|| array_key_exists(DashboardDataProvider::SHARE_NOT_BANNED_ORM_KEY, $ormParams['filter'])
			;

			//** The group query is unuseful by group id, tag id, scope or share ban filtering **/
			if (
				isset($ormParams['filter']['GROUPS.ID'])
				|| isset($ormParams['filter']['TAGS.ID'])
				|| $isScopeFilter
				|| $isShareBanFilter
				|| $listOptions->targetDashboardId !== null
			)
			{
				if ($isScopeFilter)
				{
					// SCOPE is a OneToMany relation: the =SCOPE.SCOPE_CODE join can duplicate dashboard rows.
					// Replace the join with an ID IN (subquery) so pagination counts distinct dashboards.
					$queryDashboard->disableDataDoubling();
				}

				if (!empty($ormParams['limit']) && (int)$ormParams['limit'] > 0)
				{
					$queryDashboard->setLimit((int)$ormParams['limit']);
				}

				if (!empty($ormParams['offset']) && (int)$ormParams['offset'] > 0)
				{
					$queryDashboard->setOffset((int)$ormParams['offset']);
				}

				if (
					!empty($ormParams['filter']['GROUPS.ID'])
					&& is_array($ormParams['filter']['GROUPS.ID'])
					&& count($ormParams['filter']['GROUPS.ID']) > 1
				)
				{
					$subQuery = SupersetDashboardGroupBindingTable::query()
						->setSelect(['DASHBOARD_ID', 'COUNT'])
						->registerRuntimeField('COUNT', new ExpressionField('COUNT', 'COUNT(1)'))
						->whereIn('GROUP_ID', $ormParams['filter']['GROUPS.ID'])
						->addGroup('DASHBOARD_ID')
					;

					$queryDashboard->registerRuntimeField(
						new Reference(
							'SUBQUERY_COUNT_GROUP',
							Base::getInstanceByQuery($subQuery),
							['this.ID' => 'ref.DASHBOARD_ID'],
							['join_type' => 'INNER'],
						),
					);

					$queryDashboard->addFilter('SUBQUERY_COUNT_GROUP.COUNT', count($ormParams['filter']['GROUPS.ID']));
				}

				if (
					!empty($ormParams['filter']['TAGS.ID'])
					&& is_array($ormParams['filter']['TAGS.ID'])
					&& count($ormParams['filter']['TAGS.ID']) > 1
				)
				{
					$subQuery = SupersetDashboardTagTable::query()
						->setSelect(['DASHBOARD_ID', 'COUNT'])
						->registerRuntimeField('COUNT', new ExpressionField('COUNT', 'COUNT(1)'))
						->whereIn('TAG_ID', $ormParams['filter']['TAGS.ID'])
						->addGroup('DASHBOARD_ID')
					;

					$queryDashboard->registerRuntimeField(
						new Reference(
							'SUBQUERY_TAG_GROUP',
							Base::getInstanceByQuery($subQuery),
							['this.ID' => 'ref.DASHBOARD_ID'],
							['join_type' => 'INNER'],
						),
					);

					$queryDashboard->addFilter('SUBQUERY_TAG_GROUP.COUNT', count($ormParams['filter']['TAGS.ID']));
				}

				return $queryDashboard;
			}

			$queryGroup->setFilter($groupFilter);
		}

		if (empty($ormParams['filter']))
		{
			$this->applyDashboardListOptions(
				$queryDashboard,
				$listOptions,
			);

			if ($listOptions->targetDashboardId !== null)
			{
				if (!empty($ormParams['limit']) && (int)$ormParams['limit'] > 0)
				{
					$queryDashboard->setLimit((int)$ormParams['limit']);
				}

				if (!empty($ormParams['offset']) && (int)$ormParams['offset'] > 0)
				{
					$queryDashboard->setOffset((int)$ormParams['offset']);
				}

				return $queryDashboard;
			}
		}

		$queryGroup->unionAll($queryDashboard);

		if ($applyUnionOrder)
		{
			$unionOrder = $ormParams['order'] ?? [];

			if ($hasPinnedOrder)
			{
				$unionOrder = array_merge(['MOBILE_PIN_POSITION' => 'asc', 'ENTITY_TYPE' => 'desc'], $unionOrder);
			}
			else
			{
				$unionOrder = array_merge(['ENTITY_TYPE' => 'desc'], $unionOrder);
			}

			$queryGroup->setUnionOrder($unionOrder);
		}

		if (!empty($ormParams['limit']) && (int)$ormParams['limit'] > 0)
		{
			$queryGroup->setUnionLimit((int)$ormParams['limit']);
		}

		if (!empty($ormParams['offset']) && (int)$ormParams['offset'] > 0)
		{
			$queryGroup->setUnionOffset((int)$ormParams['offset']);
		}

		return $queryGroup;
	}

	private function applyDashboardListOptions(
		Query $queryDashboard,
		DashboardGroupListOptions $listOptions,
	): void
	{
		if ($listOptions->mode === DashboardGroupListMode::RootWithPromotedDashboards)
		{
			if ($listOptions->dashboardIds === [])
			{
				$queryDashboard->where('ID', 0);
			}
			else
			{
				$queryDashboard->whereIn('ID', $listOptions->dashboardIds);
			}
		}
		elseif ($listOptions->mode === DashboardGroupListMode::FolderWithExcludedDashboards && $listOptions->dashboardIds !== [])
		{
			$queryDashboard->whereNotIn('ID', $listOptions->dashboardIds);
		}

		if ($listOptions->targetDashboardId !== null)
		{
			$queryDashboard->where('ID', $listOptions->targetDashboardId);
		}
	}

	private function registerPinnedOrderFields(
		Query $queryDashboard,
		Query $queryGroup,
		DashboardGroupListOptions $listOptions,
	): bool
	{
		if (
			$listOptions->mode !== DashboardGroupListMode::RootWithPromotedDashboards
			|| $listOptions->dashboardIds === []
		)
		{
			return false;
		}

		$expressionParts = ['CASE %s'];
		$buildFrom = ['ID'];

		foreach ($listOptions->dashboardIds as $position => $dashboardId)
		{
			$expressionParts[] = 'WHEN %s THEN %s';

			$buildFrom[] = static fn() => new SqlExpression('?i', $dashboardId);
			$buildFrom[] = static fn() => new SqlExpression('?i', $position);
		}

		$fallbackPosition = count($listOptions->dashboardIds);

		$expressionParts[] = 'ELSE %s END';
		$buildFrom[] = static fn() => new SqlExpression('?i', $fallbackPosition);

		$queryDashboard
			->registerRuntimeField(
				new ExpressionField(
					'MOBILE_PIN_POSITION',
					implode(' ', $expressionParts),
					$buildFrom,
				),
			)
			->addSelect('MOBILE_PIN_POSITION')
		;

		$queryGroup
			->registerRuntimeField(
				new ExpressionField(
					'MOBILE_PIN_POSITION',
					'%s',
					[
						static fn() => new SqlExpression('?i', $fallbackPosition),
					],
				),
			)
			->addSelect('MOBILE_PIN_POSITION')
		;

		return true;
	}
}
