<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Repository\Catalog;

use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemAccessType;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemCollection;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemForUserCollection;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;
use Bitrix\Vibecodeconnector\Internal\Model\Catalog\AccessTable;
use Bitrix\Vibecodeconnector\Internal\Model\Catalog\CatalogItemTable;
use Bitrix\Vibecodeconnector\Internal\Model\Catalog\HiddenTable;
use Bitrix\Vibecodeconnector\Internal\Model\Catalog\LastOpenedTable;
use Bitrix\Vibecodeconnector\Internal\Model\Catalog\PinTable;
use Bitrix\Vibecodeconnector\Internal\Repository\Mapper\Catalog\CatalogItemMapper;

final class CatalogItemRepository
{
	public function __construct(
		private readonly CatalogItemMapper $mapper = new CatalogItemMapper(),
	) {}

	public function getById(int $id): ?CatalogItem
	{
		$row = CatalogItemTable::getById($id)->fetch();

		return $row === false ? null : $this->mapper->fromRow($row);
	}

	public function getList(
		CatalogItemFilter $filter,
		?CatalogItemSort $sort = null,
		?CatalogItemPager $pager = null,
	): CatalogItemCollection {
		$query = $this->buildListQuery($filter, $sort, $pager, ['*']);

		$items = [];
		foreach ($query->fetchAll() as $row)
		{
			$items[] = $this->mapper->fromRow($row);
		}

		return new CatalogItemCollection(...$items);
	}

	public function getListForUser(
		int $userId,
		CatalogItemFilter $filter,
		?CatalogItemSort $sort = null,
		?CatalogItemPager $pager = null,
	): CatalogItemForUserCollection {
		$filter->userContext($userId);

		$query = $this->buildListQuery(
			$filter,
			$sort,
			$pager,
			['*', 'IS_PINNED', 'IS_OWNED_BY_USER', 'IS_LAST_OPENED', 'IS_HIDDEN'],
		);

		$views = [];
		foreach ($query->fetchAll() as $row)
		{
			$views[] = $this->mapper->fromRowForUser($row);
		}

		return new CatalogItemForUserCollection(...$views);
	}

	public function getCount(CatalogItemFilter $filter): int
	{
		return (int)$this->buildFilterQuery($filter)
			->addSelect('ID')
			->countTotal(true)
			->exec()
			->getCount()
		;
	}

	public function exists(CatalogItemFilter $filter): bool
	{
		$row = $this->buildFilterQuery($filter)
			->setSelect(['ID'])
			->setLimit(1)
			->fetch()
		;

		return $row !== false;
	}

	public function getByOwnerId(int $ownerId): CatalogItemCollection
	{
		return $this->getList(
			(new CatalogItemFilter())->ownerUserId($ownerId),
		);
	}

	/**
	 * @param int[] $catalogItemIds
	 */
	public function getByIds(array $catalogItemIds): CatalogItemCollection
	{
		if ($catalogItemIds === [])
		{
			return new CatalogItemCollection();
		}

		return $this->getList(
			(new CatalogItemFilter())->ids($catalogItemIds),
		);
	}

	public function getAll(): CatalogItemCollection
	{
		return $this->getList(new CatalogItemFilter());
	}

	/**
	 * @param string[] $userCodes
	 */
	public function isAccessibleToUser(int $catalogItemId, int $userId, array $userCodes): bool
	{
		return $this->exists(
			(new CatalogItemFilter())
				->id($catalogItemId)
				->accessibleToUser($userId, $userCodes),
		);
	}

	private function buildListQuery(
		CatalogItemFilter $filter,
		?CatalogItemSort $sort,
		?CatalogItemPager $pager,
		array $select,
	): Query {
		$sort ??= (new CatalogItemSort())->byCreatedAt();
		$pager ??= new CatalogItemPager();

		$query = $this->buildFilterQuery($filter)
			->setSelect($select)
			->setOffset($pager->getOffset())
			->setLimit($pager->getLimit())
		;

		foreach ($sort->toArray() as $field => $direction)
		{
			$query->addOrder($field, $direction);
		}

		return $query;
	}

	private function buildFilterQuery(CatalogItemFilter $filter): Query
	{
		$query = CatalogItemTable::query();

		$userContextId = $filter->getUserContextId();
		if ($userContextId !== null)
		{
			$query->registerRuntimeField(
				new Reference(
					'USER_PIN',
					PinTable::class,
					[
						'=this.ID' => 'ref.CATALOG_ITEM_ID',
						'=ref.USER_ID' => new SqlExpression('?i', $userContextId),
					],
					['join_type' => Join::TYPE_LEFT],
				)
			);
			$query->registerRuntimeField($this->isPinnedExpression());
			$query->registerRuntimeField($this->isOwnedByUserExpression($userContextId));
			$query->registerRuntimeField(
				new Reference(
					'USER_LAST_OPENED',
					LastOpenedTable::class,
					[
						'=this.ID' => 'ref.CATALOG_ITEM_ID',
						'=ref.USER_ID' => new SqlExpression('?i', $userContextId),
					],
					['join_type' => Join::TYPE_LEFT],
				)
			);
			$query->registerRuntimeField($this->getIsLastOpenedExpression());
			$query->registerRuntimeField(
				new Reference(
					'USER_HIDDEN',
					HiddenTable::class,
					[
						'=this.ID' => 'ref.CATALOG_ITEM_ID',
						'=ref.USER_ID' => new SqlExpression('?i', $userContextId),
					],
					['join_type' => Join::TYPE_LEFT],
				)
			);
			$query->registerRuntimeField($this->isHiddenExpression());

			$hiddenState = $filter->getHiddenState();
			if ($hiddenState === true)
			{
				$query->whereNotNull('USER_HIDDEN.HIDDEN_AT');
			}
			elseif ($hiddenState === false)
			{
				$query->whereNull('USER_HIDDEN.HIDDEN_AT');
			}

			$notOpenedByUser = $filter->getNotOpenedByUser();
			if ($notOpenedByUser === true)
			{
				$query->whereNull('USER_LAST_OPENED.OPENED_AT');
			}
			elseif ($notOpenedByUser === false)
			{
				$query->whereNotNull('USER_LAST_OPENED.OPENED_AT');
			}
		}

		if ($filter->getId() !== null)
		{
			$query->where('ID', $filter->getId());
		}

		if ($filter->getIds() !== null)
		{
			$query->whereIn('ID', $filter->getIds());
		}

		if ($filter->getOwnerUserId() !== null)
		{
			$query->where('OWNER_ID', $filter->getOwnerUserId());
		}

		if ($filter->getOwnerUserNotId() !== null)
		{
			$query->where('OWNER_ID', '!=', $filter->getOwnerUserNotId());
		}

		if ($filter->getAccessType() !== null)
		{
			$query->where('ACCESS_TYPE', $filter->getAccessType()->value);
		}

		if ($filter->getAccessTypeNot() !== null)
		{
			$query->where('ACCESS_TYPE', '!=', $filter->getAccessTypeNot()->value);
		}

		$accessTypeIn = $filter->getAccessTypeIn();
		if ($accessTypeIn !== null && $accessTypeIn !== [])
		{
			$query->whereIn(
				'ACCESS_TYPE',
				array_map(static fn(CatalogItemAccessType $t) => $t->value, $accessTypeIn),
			);
		}

		if ($filter->getType() !== null)
		{
			$query->where('TYPE', $filter->getType()->value);
		}

		$accessibleUserId = $filter->getAccessibleToUserId();
		if ($accessibleUserId !== null)
		{
			$accessFilter = Query::filter()
				->logic('or')
				->where('OWNER_ID', $accessibleUserId)
				->where('ACCESS_TYPE', CatalogItemAccessType::Public->value)
			;
			$accessibleCodes = $filter->getAccessibleToUserCodes() ?? [];
			if ($accessibleCodes !== [])
			{
				$accessFilter->where(
					Query::filter()
						->where('ACCESS_TYPE', CatalogItemAccessType::ACL->value)
						->whereIn('ID', $this->itemIdsGrantedTo($accessibleCodes))
				);
			}
			$query->where($accessFilter);
		}

		$notGrantedCodes = $filter->getNotGrantedToUserCodes();
		if ($notGrantedCodes !== null && $notGrantedCodes !== [])
		{
			$query->whereNotIn('ID', $this->itemIdsGrantedTo($notGrantedCodes));
		}

		$queryText = $filter->getQuery();
		if ($queryText !== null)
		{
			$query->where(
				Query::filter()
					->logic('or')
					->whereLike('TITLE', '%' . $queryText . '%')
					->whereLike('DESCRIPTION', '%' . $queryText . '%')
			);
		}

		if ($filter->getWhere() !== null)
		{
			$query->where($filter->getWhere());
		}

		if ($filter->getPairingIss() !== null)
		{
			$query->where('PAIRING_ISS', $filter->getPairingIss());
		}

		if (!$filter->getIncludeDeactivated())
		{
			$query->whereNull('DEACTIVATED_AT');
		}

		return $query;
	}

	private function isPinnedExpression(): ExpressionField
	{
		return new ExpressionField(
			'IS_PINNED',
			'CASE WHEN %s IS NOT NULL THEN 1 ELSE 0 END',
			['USER_PIN.PINNED_AT'],
		);
	}

	private function isOwnedByUserExpression(int $userId): ExpressionField
	{
		return new ExpressionField(
			'IS_OWNED_BY_USER',
			'CASE WHEN %s = ' . $userId . ' THEN 1 ELSE 0 END',
			['OWNER_ID'],
		);
	}

	private function isHiddenExpression(): ExpressionField
	{
		return new ExpressionField(
			'IS_HIDDEN',
			'CASE WHEN %s IS NOT NULL THEN 1 ELSE 0 END',
			['USER_HIDDEN.HIDDEN_AT'],
		);
	}

	private function getIsLastOpenedExpression(): ExpressionField
	{
		return new ExpressionField(
			'IS_LAST_OPENED',
			'CASE WHEN %s IS NOT NULL THEN 1 ELSE 0 END',
			['USER_LAST_OPENED.OPENED_AT'],
		);
	}

	/**
	 * @param string[] $userCodes
	 */
	private function itemIdsGrantedTo(array $userCodes): Query
	{
		return AccessTable::query()
			->setSelect(['CATALOG_ITEM_ID'])
			->whereIn('ACCESS_CODE', $userCodes);
	}

	/**
	 * @param string[] $userCodes
	 * @param int[]|null $restrictToIds
	 * @return int[]
	 */
	public function getNewAppItemIds(int $userId, array $userCodes, DateTime $baseline, ?array $restrictToIds = null): array
	{
		if ($restrictToIds !== null && $restrictToIds === [])
		{
			return [];
		}

		$query = $this->newAppItemsQuery($userId, $userCodes, $baseline)->setSelect(['ID']);
		if ($restrictToIds !== null)
		{
			$query->whereIn('ID', $restrictToIds);
		}

		return array_map(static fn(array $row): int => (int)$row['ID'], $query->fetchAll());
	}

	/**
	 * @param string[] $userCodes
	 */
	public function getNewAppCount(int $userId, array $userCodes, DateTime $baseline): int
	{
		return (int)$this->newAppItemsQuery($userId, $userCodes, $baseline)
			->queryCountTotal()
		;
	}

	/**
	 * @param string[] $userCodes
	 */
	private function newAppItemsQuery(int $userId, array $userCodes, DateTime $baseline): Query
	{
		$filter = (new CatalogItemFilter())
			->accessibleToUser($userId, $userCodes)
			->type(CatalogItemType::Application)
			->ownerUserNotId($userId)
			->notOpenedByUser(true)
			->hiddenState(false)
		;
		$filter->userContext($userId);

		return $this->buildFilterQuery($filter)->where($this->noveltyCondition($userCodes, $baseline));
	}

	/**
	 * "New for the user" predicate: created after the baseline, or an ACL grant
	 * received after it. Reused by the count/id queries and by the paginated
	 * New-state list so the criterion has a single source of truth.
	 *
	 * @param string[] $userCodes
	 */
	private function noveltyCondition(array $userCodes, DateTime $baseline): ConditionTree
	{
		$novelty = Query::filter()
			->logic('or')
			->where('CREATED_AT', '>', $baseline)
		;
		if ($userCodes !== [])
		{
			$novelty->where(
				Query::filter()
					->where('ACCESS_TYPE', CatalogItemAccessType::ACL->value)
					->whereIn('ID', $this->itemIdsGrantedAfter($userCodes, $baseline))
			);
		}

		return $novelty;
	}

	/**
	 * Paginated list of the user's new apps, with the novelty predicate folded
	 * into the list query so no full ID set is materialized before pagination.
	 *
	 * @param string[] $userCodes
	 */
	public function getNewAppListForUser(
		int $userId,
		array $userCodes,
		DateTime $baseline,
		CatalogItemFilter $filter,
		?CatalogItemSort $sort = null,
		?CatalogItemPager $pager = null,
	): CatalogItemForUserCollection {
		$filter->userContext($userId);

		$query = $this->buildListQuery(
			$filter,
			$sort,
			$pager,
			['*', 'IS_PINNED', 'IS_OWNED_BY_USER', 'IS_LAST_OPENED', 'IS_HIDDEN'],
		);
		$query->where($this->noveltyCondition($userCodes, $baseline));

		$views = [];
		foreach ($query->fetchAll() as $row)
		{
			$views[] = $this->mapper->fromRowForUser($row);
		}

		return new CatalogItemForUserCollection(...$views);
	}

	/**
	 * @param string[] $userCodes
	 */
	private function itemIdsGrantedAfter(array $userCodes, DateTime $baseline): Query
	{
		return AccessTable::query()
			->setSelect(['CATALOG_ITEM_ID'])
			->whereIn('ACCESS_CODE', $userCodes)
			->where('GRANTED_AT', '>', $baseline);
	}

	/**
	 * @throws PersistenceException
	 */
	public function save(CatalogItem $item): void
	{
		$now = new DateTime();
		if ($item->getCreatedAt() === null)
		{
			$item->setCreatedAt($now);
		}
		$item->setUpdatedAt($now);

		$row = $this->mapper->toRow($item);

		if ($item->getId() === null)
		{
			try
			{
				$result = CatalogItemTable::add($row);
			}
			catch (\Throwable $e)
			{
				throw new PersistenceException($e->getMessage(), previous: $e);
			}

			if (!$result->isSuccess())
			{
				throw new PersistenceException(
					'Failed to insert catalog item: ' . implode('; ', $result->getErrorMessages())
				);
			}
			$item->setId((int)$result->getId());

			return;
		}

		try
		{
			$result = CatalogItemTable::update($item->getId(), $row);
		}
		catch (\Throwable $e)
		{
			throw new PersistenceException($e->getMessage(), previous: $e);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				'Failed to update catalog item: ' . implode('; ', $result->getErrorMessages())
			);
		}
	}

	public function deactivateByPairingIss(string $iss, DateTime $now): int
	{
		if ($iss === '')
		{
			return 0;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$table = $helper->quote(CatalogItemTable::getTableName());
		$datetime = $helper->convertToDbDateTime($now);

		$connection->queryExecute(
			'UPDATE ' . $table
			. ' SET ' . $helper->quote('DEACTIVATED_AT') . ' = ' . $datetime
			. ', ' . $helper->quote('UPDATED_AT') . ' = ' . $datetime
			. ' WHERE ' . $helper->quote('PAIRING_ISS') . " = '" . $helper->forSql($iss) . "'"
			. ' AND ' . $helper->quote('DEACTIVATED_AT') . ' IS NULL'
		);

		return $connection->getAffectedRowsCount();
	}

	/**
	 * @throws PersistenceException
	 */
	public function delete(int $id): void
	{
		try
		{
			$result = CatalogItemTable::delete($id);
		}
		catch (\Throwable $e)
		{
			throw new PersistenceException($e->getMessage(), previous: $e);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException('Unable to delete catalog item');
		}
	}
}
