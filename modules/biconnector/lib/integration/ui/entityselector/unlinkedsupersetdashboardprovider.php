<?php

namespace Bitrix\BIConnector\Integration\UI\EntitySelector;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Integrator\Dto;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;

class UnlinkedSupersetDashboardProvider extends BaseProvider
{
	public const ENTITY_ID = 'biconnector-superset-unlinked-dashboard';

	private const ITEM_AVATAR_PATH = '/bitrix/images/biconnector/superset-dashboard-selector/icon-type-attached.svg';

	/**
	 * Above this count of linked dashboards we filter unlinked items locally
	 * instead of pushing N `neq`-filters to Superset query — protects Superset
	 * from query-string overflow and SQL bloat on large portals.
	 */
	private const NEQ_IDS_PROXY_LIMIT = 50;

	public function __construct(array $options = [])
	{
		parent::__construct();
	}

	public function isAvailable(): bool
	{
		return
			$GLOBALS['USER']->isAuthorized()
			&& AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT)
		;
	}

	public function fillDialog(Dialog $dialog): void
	{
		$items = $this->getUnlinkedItems();
		$dialog->addRecentItems($items);
	}

	public function getItems(array $ids): array
	{
		if (empty($ids))
		{
			return [];
		}

		$integrator = IntegratorFactory::getInstance();
		$response = $integrator->getDashboardListByFilter(['ids' => array_map('intval', $ids)]);
		if ($response->hasErrors())
		{
			return [];
		}

		/** @var Dto\DashboardList $dashboardList */
		$dashboardList = $response->getData();

		return array_map(
			fn(Dto\Dashboard $dashboard) => $this->makeItem($dashboard),
			$dashboardList->dashboards,
		);
	}

	/**
	 * @return Item[]
	 */
	private function getUnlinkedItems(): array
	{
		$linkedExternalIds = $this->getLinkedExternalIds();
		$useProxyFilter = !empty($linkedExternalIds) && count($linkedExternalIds) <= self::NEQ_IDS_PROXY_LIMIT;

		$filter = $useProxyFilter ? ['neqIds' => $linkedExternalIds] : [];

		$integrator = IntegratorFactory::getInstance();
		$response = $integrator->getDashboardListByFilter($filter);
		if ($response->hasErrors())
		{
			return [];
		}

		/** @var Dto\DashboardList $dashboardList */
		$dashboardList = $response->getData();
		$dashboards = $dashboardList->dashboards;

		if (!$useProxyFilter && !empty($linkedExternalIds))
		{
			$linkedSet = array_flip($linkedExternalIds);
			$dashboards = array_filter(
				$dashboards,
				static fn(Dto\Dashboard $dashboard) => !isset($linkedSet[$dashboard->id]),
			);
		}

		return array_map(
			fn(Dto\Dashboard $dashboard) => $this->makeItem($dashboard),
			$dashboards,
		);
	}

	/**
	 * @return int[]
	 */
	private function getLinkedExternalIds(): array
	{
		$result = [];
		$dbResult = SupersetDashboardTable::getList([
			'select' => ['EXTERNAL_ID'],
			'filter' => ['>EXTERNAL_ID' => 0],
		]);
		while ($row = $dbResult->fetch())
		{
			$result[] = (int)$row['EXTERNAL_ID'];
		}

		return $result;
	}

	private function makeItem(Dto\Dashboard $dashboard): Item
	{
		return new Item([
			'id' => $dashboard->id,
			'entityId' => self::ENTITY_ID,
			'title' => $dashboard->title,
			'avatar' => self::ITEM_AVATAR_PATH,
			'avatarOptions' => [
				'borderRadius' => '4px',
			],
			'customData' => [
				'externalId' => $dashboard->id,
				'title' => $dashboard->title,
				'published' => $dashboard->published,
			],
		]);
	}
}
