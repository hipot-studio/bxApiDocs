<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dashboard;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\UrlParameter\UrlParameters;
use Bitrix\BIConnector\Internal\Model\SupersetDashboardInfoTable;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\Result;

/**
 * Dashboards the actor may view, a page at a time. The list is narrowed by the view right and never
 * refuses: a user without rights gets an empty page.
 *
 * Data: `dashboards`, `has_more`, `total` — the answer of list_dashboards.
 */
final class ListDashboardsOperation
{
	public const PAGE_SIZE = 50;

	/** @var \Closure(int): AccessController */
	private readonly \Closure $accessControllerFactory;

	/**
	 * @param (\Closure(int): AccessController)|null $accessControllerFactory
	 */
	public function __construct(?\Closure $accessControllerFactory = null)
	{
		$this->accessControllerFactory = $accessControllerFactory
			?? static fn(int $userId): AccessController => new AccessController($userId)
		;
	}

	public function execute(ListDashboardsQuery $query, Actor $actor): Result
	{
		$result = new Result();

		$allowedIds = ($this->accessControllerFactory)($actor->userId)->getAllowedDashboardValue(
			ActionDictionary::ACTION_BIC_DASHBOARD_VIEW,
		);

		$filter = [];
		if ($allowedIds !== null)
		{
			if (empty($allowedIds))
			{
				return $result->setData(['dashboards' => [], 'has_more' => false, 'total' => 0]);
			}
			$filter['=ID'] = $allowedIds;
		}

		if ($query->query !== '')
		{
			$filter[] = [
				'LOGIC' => 'OR',
				'%TITLE' => $query->query,
				'%INFO.DESCRIPTION' => $query->query,
			];
		}

		$list = SupersetDashboardTable::getList([
			'select' => ['ID', 'TITLE', 'TYPE', 'DESCRIPTION' => 'INFO.DESCRIPTION'],
			'filter' => $filter,
			'order' => ['TITLE' => 'ASC', 'ID' => 'ASC'],
			'limit' => self::PAGE_SIZE + 1,
			'offset' => $query->offset,
			'count_total' => true,
			'runtime' => [
				new ReferenceField(
					'INFO',
					SupersetDashboardInfoTable::class,
					['=this.ID' => 'ref.DASHBOARD_ID'],
					['join_type' => 'LEFT'],
				),
			],
		]);
		$rows = $list->fetchAll();
		$total = (int)$list->getCount();

		$hasMore = count($rows) > self::PAGE_SIZE;
		if ($hasMore)
		{
			array_pop($rows);
		}

		$pageIds = array_map(static fn(array $row): int => (int)$row['ID'], $rows);
		$requiresScope = UrlParameters::requiresScopeBatch($pageIds);

		$dashboards = [];
		foreach ($rows as $row)
		{
			$id = (int)$row['ID'];
			$description = (string)($row['DESCRIPTION'] ?? '');
			$dashboards[] = [
				'id' => $id,
				'title' => $row['TITLE'],
				'type' => $row['TYPE'] ?? null,
				'description' => $description !== '' ? $description : null,
				'requiresScope' => $requiresScope[$id] ?? false,
			];
		}

		return $result->setData(['dashboards' => $dashboards, 'has_more' => $hasMore, 'total' => $total]);
	}
}
