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
use Bitrix\Main\Web\Json;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemAccessType;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemCollection;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemForUserCollection;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;
use Bitrix\Vibecodeconnector\Internal\Model\Catalog\AccessTable;
use Bitrix\Vibecodeconnector\Internal\Model\Catalog\CatalogItemTable;
use Bitrix\Vibecodeconnector\Internal\Model\Catalog\FavoriteTable;
use Bitrix\Vibecodeconnector\Internal\Model\Catalog\HiddenTable;
use Bitrix\Vibecodeconnector\Internal\Model\Catalog\LastOpenedTable;
use Bitrix\Vibecodeconnector\Internal\Model\Catalog\PinTable;
use Bitrix\Vibecodeconnector\Internal\Model\Catalog\ViewedTable;
use Bitrix\Vibecodeconnector\Internal\Repository\Mapper\Catalog\CatalogItemMapper;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Identity\AppIdentityHash;

class CatalogItemRepository
{
	private const APP_CHAT_ID_COLUMN = 'MESSENGER_CHAT_ID';
	private const APP_CHAT_TEARDOWN_PENDING_COLUMN = 'APP_CHAT_TEARDOWN_PENDING';

	private const APP_CHAT_SYNC_REVISION_COLUMN = 'APP_CHAT_SYNC_REVISION';
	private const APP_CHAT_SYNCED_REVISION_COLUMN = 'APP_CHAT_SYNCED_REVISION';
	private const APP_CHAT_SYNC_PENDING_COLUMN = 'APP_CHAT_SYNC_PENDING';
	private const APP_CHAT_WELCOME_COLUMN = 'APP_CHAT_WELCOME_MESSAGE_ID';

	public function __construct(
		private readonly CatalogItemMapper $mapper = new CatalogItemMapper(),
	) {}

	public function getById(int $id): ?CatalogItem
	{
		$row = CatalogItemTable::getById($id)->fetch();

		return $row === false ? null : $this->mapper->fromRow($row);
	}

	public function findIdByViewAddress(string $viewHost, string $viewPath): ?int
	{
		if ($viewHost === '')
		{
			return null;
		}

		$urls = [];
		foreach (['https://', 'http://'] as $scheme)
		{
			$urls[] = $scheme . $viewHost . $viewPath;
			$urls[] = $scheme . $viewHost . $viewPath . '/';
		}

		$row = $this->buildFilterQuery((new CatalogItemFilter())->type(CatalogItemType::Application))
			->setSelect(['ID'])
			->whereIn('VIEW_URL', $urls)
			->setOrder(['ID' => 'ASC'])
			->setLimit(1)
			->fetch()
		;

		return $row === false ? null : (int)$row['ID'];
	}

	public function getReleaseTarget(
		string $externalId,
		string $serverIss,
		bool $isPairedPrincipal,
	): ?CatalogItem {
		if ($externalId === '' || $serverIss === '')
		{
			return null;
		}

		$pairedHash = AppIdentityHash::calculate($serverIss, $externalId);
		$hashes = [$pairedHash];
		if (!$isPairedPrincipal)
		{
			$hashes[] = AppIdentityHash::calculate(null, $externalId);
		}
		$rows = CatalogItemTable::query()
			->setSelect(['*'])
			->whereIn('APP_IDENTITY_HASH', array_values(array_unique($hashes)))
			->whereNull('DEACTIVATED_AT')
			->setLimit(count($hashes))
			->fetchAll()
		;

		$unbound = null;
		foreach ($rows as $row)
		{
			if (($row['EXTERNAL_ID'] ?? null) !== $externalId)
			{
				continue;
			}
			if (($row['PAIRING_ISS'] ?? null) === $serverIss)
			{
				return $this->mapper->fromRow($row);
			}
			if (!$isPairedPrincipal && ($row['PAIRING_ISS'] ?? null) === null)
			{
				$unbound = $row;
			}
		}

		return $unbound === null ? null : $this->mapper->fromRow($unbound);
	}

	public function hasExactExternalId(string $externalId): bool
	{
		$rows = CatalogItemTable::query()
			->setSelect(['EXTERNAL_ID'])
			->where('EXTERNAL_ID', $externalId)
			->fetchAll()
		;
		foreach ($rows as $row)
		{
			if (($row['EXTERNAL_ID'] ?? null) === $externalId)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells whether the app identity is already taken, i.e. whether `ux_vcc_catalog_app_identity`
	 * would reject a row carrying this hash.
	 */
	public function existsByAppIdentityHash(string $identityHash, int $exceptCatalogItemId = 0): bool
	{
		if ($identityHash === '')
		{
			return false;
		}

		$rows = CatalogItemTable::query()
			->setSelect(['ID'])
			->where('APP_IDENTITY_HASH', $identityHash)
			->setLimit(2)
			->fetchAll()
		;
		foreach ($rows as $row)
		{
			if ((int)$row['ID'] !== $exceptCatalogItemId)
			{
				return true;
			}
		}

		return false;
	}

	public function getLastReleaseVersion(int $catalogItemId): ?int
	{
		if ($catalogItemId <= 0)
		{
			return null;
		}

		$row = CatalogItemTable::query()
			->setSelect(['LAST_RELEASE_VERSION'])
			->where('ID', $catalogItemId)
			->setLimit(1)
			->fetch()
		;

		return $row === false || $row['LAST_RELEASE_VERSION'] === null
			? null
			: (int)$row['LAST_RELEASE_VERSION'];
	}

	public function setLastReleaseVersion(int $catalogItemId, int $versionNumber): void
	{
		$result = CatalogItemTable::update($catalogItemId, ['LAST_RELEASE_VERSION' => $versionNumber]);
		if (!$result->isSuccess())
		{
			throw new PersistenceException(implode('; ', $result->getErrorMessages()));
		}
	}

	public function compareAndSetLastReleaseVersion(
		int $catalogItemId,
		?int $expectedVersionNumber,
		?int $versionNumber,
	): bool {
		if ($catalogItemId <= 0)
		{
			return false;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$column = $helper->quote('LAST_RELEASE_VERSION');
		$expected = $expectedVersionNumber === null
			? $column . ' IS NULL'
			: $column . ' = ' . $expectedVersionNumber;
		$next = $versionNumber === null ? 'NULL' : (string)$versionNumber;
		$connection->queryExecute(
			'UPDATE ' . $helper->quote(CatalogItemTable::getTableName())
			. ' SET ' . $column . ' = ' . $next
			. ' WHERE ' . $helper->quote('ID') . ' = ' . $catalogItemId
			. ' AND ' . $expected,
		);

		if ($connection->getAffectedRowsCount() > 0)
		{
			return true;
		}

		return $this->getLastReleaseVersion($catalogItemId) === $versionNumber;
	}

	/**
	 * Stamps the pairing issuer on an item that carries none, keeping the derived identity hash in
	 * sync so `getReleaseTarget()` can find the item afterwards.
	 *
	 * The `WHERE` clause is the machine-enforced part of the contract: `PAIRING_ISS` must still be
	 * empty (write-once, two concurrent binds cannot both win) and `EXTERNAL_ID` must still hold the
	 * value the identity hash was calculated from. Without the latter a concurrent `update` that
	 * renames `EXTERNAL_ID` between the read and this statement would leave a hash of the previous
	 * name behind, and `getReleaseTarget()` would find the card by neither name.
	 *
	 * @return bool true when the item was bound by this call; false when the row no longer matches
	 *              those expectations - the caller re-reads the item and decides.
	 *
	 * @throws \Bitrix\Main\DB\DuplicateEntryException when another item already holds the identity.
	 */
	public function bindPairingIss(int $catalogItemId, string $iss, ?string $externalId): bool
	{
		if ($catalogItemId <= 0 || $iss === '')
		{
			return false;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$identityHash = AppIdentityHash::calculate($iss, $externalId);
		$now = $helper->convertToDbDateTime(new DateTime());
		$pairingIssColumn = $helper->quote('PAIRING_ISS');
		$externalIdColumn = $helper->quote('EXTERNAL_ID');
		$externalIdCondition = $externalId === null
			? $externalIdColumn . ' IS NULL'
			: $externalIdColumn . " = '" . $helper->forSql($externalId) . "'";

		$connection->queryExecute(
			'UPDATE ' . $helper->quote(CatalogItemTable::getTableName())
			. ' SET ' . $pairingIssColumn . " = '" . $helper->forSql($iss) . "'"
			. ', ' . $helper->quote('APP_IDENTITY_HASH') . ' = '
			. ($identityHash === null ? 'NULL' : "'" . $helper->forSql($identityHash) . "'")
			. ', ' . $helper->quote('UPDATED_AT') . ' = ' . $now
			. ' WHERE ' . $helper->quote('ID') . ' = ' . $catalogItemId
			. ' AND (' . $pairingIssColumn . ' IS NULL OR ' . $pairingIssColumn . " = '')"
			. ' AND ' . $externalIdCondition
			. ' AND ' . $helper->quote('DEACTIVATED_AT') . ' IS NULL',
		);

		return $connection->getAffectedRowsCount() > 0;
	}

	public function getByAppChatId(int $chatId): ?CatalogItem
	{
		return $this->getByAppChatIds([$chatId])[$chatId] ?? null;
	}

	public function getByAppChatIds(array $chatIds): array
	{
		$chatIds = array_values(array_unique(array_filter(
			array_map('intval', $chatIds),
			static fn (int $chatId): bool => $chatId > 0,
		)));
		if ($chatIds === [])
		{
			return [];
		}

		$query = $this->buildFilterQuery(
			(new CatalogItemFilter())->appChatIds($chatIds)->includeDeactivated(),
		)
			->registerRuntimeField(new ExpressionField('APP_CHAT_ID', self::APP_CHAT_ID_COLUMN))
			->setSelect(['*', 'APP_CHAT_ID'])
		;

		$items = [];
		foreach ($query->fetchAll() as $row)
		{
			$items[(int)$row['APP_CHAT_ID']] = $this->mapper->fromRow($row);
		}

		return $items;
	}

	public static function supportsRowLocking(): bool
	{
		return in_array(Application::getConnection()->getType(), ['mysql', 'pgsql'], true);
	}

	public function lockById(int $id): void
	{
		if (!self::supportsRowLocking())
		{
			return;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$connection->queryExecute(
			'SELECT ' . $helper->quote('ID')
			. ' FROM ' . $helper->quote(CatalogItemTable::getTableName())
			. ' WHERE ' . $helper->quote('ID') . ' = ' . $id
			. ' FOR UPDATE',
		);
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
			['*', 'IS_PINNED', 'IS_OWNED_BY_USER', 'IS_LAST_OPENED', 'IS_HIDDEN', 'IS_FAVORITE'],
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

	public function getIdsAfter(CatalogItemFilter $filter, int $afterId, int $limit): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		$rows = $this->buildFilterQuery($filter)
			->setSelect(['ID'])
			->where('ID', '>', max(0, $afterId))
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['ID'], $rows);
	}

	public function getItemsNeedingAppChatTeardown(int $afterId, int $limit): CatalogItemCollection
	{
		if ($limit <= 0)
		{
			return new CatalogItemCollection();
		}

		$query = $this->buildFilterQuery((new CatalogItemFilter())->needsAppChatTeardown())
			->setSelect(['*'])
			->where('ID', '>', max(0, $afterId))
			->addOrder('ID', 'ASC')
			->setLimit($limit)
		;
		$items = [];
		foreach ($query->fetchAll() as $row)
		{
			$items[] = $this->mapper->fromRow($row);
		}

		return new CatalogItemCollection(...$items);
	}

	public function getItemsWithAppChat(int $afterId, int $limit): CatalogItemCollection
	{
		if ($limit <= 0)
		{
			return new CatalogItemCollection();
		}

		$query = $this->buildFilterQuery((new CatalogItemFilter())->includeDeactivated())
			->setSelect(['*'])
			->where(self::APP_CHAT_ID_COLUMN, '>', 0)
			->where('ID', '>', max(0, $afterId))
			->addOrder('ID', 'ASC')
			->setLimit($limit)
		;
		$items = [];
		foreach ($query->fetchAll() as $row)
		{
			$items[] = $this->mapper->fromRow($row);
		}

		return new CatalogItemCollection(...$items);
	}

	public function getLiveAppChatItems(int $afterId, int $limit): CatalogItemCollection
	{
		if ($limit <= 0)
		{
			return new CatalogItemCollection();
		}

		$query = $this->buildFilterQuery(
			(new CatalogItemFilter())
				->type(CatalogItemType::Application)
				->hasAppChat(),
		)
			->setSelect(['*'])
			->where('ID', '>', max(0, $afterId))
			->addOrder('ID', 'ASC')
			->setLimit($limit)
		;
		$items = [];
		foreach ($query->fetchAll() as $row)
		{
			$items[] = $this->mapper->fromRow($row);
		}

		return new CatalogItemCollection(...$items);
	}

	public function getLiveAppItemIdsAfter(int $afterId, int $limit): array
	{
		return $this->getIdsAfter(
			(new CatalogItemFilter())->type(CatalogItemType::Application),
			$afterId,
			$limit,
		);
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
		$appChatIds = $filter->getAppChatIds();
		if ($appChatIds !== null)
		{
			$query
				->registerRuntimeField(new ExpressionField('APP_CHAT_ID_FILTER', self::APP_CHAT_ID_COLUMN))
				->whereIn('APP_CHAT_ID_FILTER', $appChatIds)
			;
		}

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
				),
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
				),
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
				),
			);
			$query->registerRuntimeField($this->isHiddenExpression());
			$query->registerRuntimeField(
				new Reference(
					'USER_FAVORITE',
					FavoriteTable::class,
					[
						'=this.ID' => 'ref.CATALOG_ITEM_ID',
						'=ref.USER_ID' => new SqlExpression('?i', $userContextId),
					],
					['join_type' => Join::TYPE_LEFT],
				)
			);
			$query->registerRuntimeField($this->isFavoriteExpression());
			$query->registerRuntimeField(
				new Reference(
					'USER_VIEWED',
					ViewedTable::class,
					[
						'=this.ID' => 'ref.CATALOG_ITEM_ID',
						'=ref.USER_ID' => new SqlExpression('?i', $userContextId),
					],
					['join_type' => Join::TYPE_LEFT],
				),
			);

			$hiddenState = $filter->getHiddenState();
			if ($hiddenState === true)
			{
				$query->whereNotNull('USER_HIDDEN.HIDDEN_AT');
			}
			elseif ($hiddenState === false)
			{
				$query->whereNull('USER_HIDDEN.HIDDEN_AT');
			}

			$favoriteState = $filter->getFavoriteState();
			if ($favoriteState === true)
			{
				$query->whereNotNull('USER_FAVORITE.FAVORITED_AT');
			}
			elseif ($favoriteState === false)
			{
				$query->whereNull('USER_FAVORITE.FAVORITED_AT');
			}

			$notViewedByUser = $filter->getNotViewedByUser();
			if ($notViewedByUser === true)
			{
				$query->where($this->notViewedCondition($filter->getViewSession()));
			}
			elseif ($notViewedByUser === false)
			{
				$query->whereNotNull('USER_VIEWED.VIEWED_AT');
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
						->whereIn('ID', $this->itemIdsGrantedTo($accessibleCodes)),
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
					->whereLike('DESCRIPTION', '%' . $queryText . '%'),
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

		if ($filter->getPairingIssOrUnbound() !== null)
		{
			$query->where(
				Query::filter()
					->logic('or')
					->where('PAIRING_ISS', $filter->getPairingIssOrUnbound())
					->whereNull('PAIRING_ISS'),
			);
		}

		if ($filter->getExternalId() !== null)
		{
			$query->where('EXTERNAL_ID', $filter->getExternalId());
		}

		if ($filter->getNeedsAppChatTeardown())
		{
			$this->whereAppChatTeardownPending($query);
		}

		if ($filter->getNeedsAppChatSync())
		{
			$this->whereAppChatSyncPending($query);
		}

		if ($filter->getHasAppChat())
		{
			$query->where(self::APP_CHAT_ID_COLUMN, '>', 0);
		}

		if (!$filter->getIncludeDeactivated() && !$filter->getNeedsAppChatTeardown())
		{
			$query->whereNull('DEACTIVATED_AT');
		}

		return $query;
	}

	/**
	 * Not viewed yet, plus everything marked since the given view session started.
	 * Marking happens right after a page is served, so without the session the served
	 * page would drop out of the New state and the next offset would skip exactly as
	 * many apps as were shown.
	 */
	private function notViewedCondition(?DateTime $viewSession): ConditionTree
	{
		$condition = Query::filter()
			->logic('or')
			->whereNull('USER_VIEWED.VIEWED_AT')
		;
		if ($viewSession !== null)
		{
			$condition->where('USER_VIEWED.VIEWED_AT', '>=', $viewSession);
		}

		return $condition;
	}

	private function whereAppChatTeardownPending(Query $query): void
	{
		$query->where(self::APP_CHAT_TEARDOWN_PENDING_COLUMN, 1);
	}

	private function whereAppChatSyncPending(Query $query): void
	{
		$query->where(self::APP_CHAT_SYNC_PENDING_COLUMN, 1);
	}

	public function getAppChatSyncRevision(int $catalogItemId): int
	{
		if ($catalogItemId <= 0)
		{
			return 0;
		}

		$row = CatalogItemTable::query()
			->setSelect([self::APP_CHAT_SYNC_REVISION_COLUMN])
			->where('ID', $catalogItemId)
			->setLimit(1)
			->fetch()
		;

		return $row === false ? 0 : (int)$row[self::APP_CHAT_SYNC_REVISION_COLUMN];
	}

	public function getAppChatSyncedRevision(int $catalogItemId): int
	{
		if ($catalogItemId <= 0)
		{
			return 0;
		}

		$row = CatalogItemTable::query()
			->setSelect([self::APP_CHAT_SYNCED_REVISION_COLUMN])
			->where('ID', $catalogItemId)
			->setLimit(1)
			->fetch()
		;

		return $row === false ? 0 : (int)$row[self::APP_CHAT_SYNCED_REVISION_COLUMN];
	}

	public function bumpAppChatSyncRevision(int $catalogItemId): void
	{
		if ($catalogItemId <= 0)
		{
			return;
		}

		$this->bumpAppChatSyncRevisions([$catalogItemId]);
	}

	/**
	 * @param int[] $catalogItemIds
	 */
	public function bumpAppChatSyncRevisions(array $catalogItemIds): void
	{
		$catalogItemIds = array_values(array_unique(array_filter(
			array_map('intval', $catalogItemIds),
			static fn (int $id): bool => $id > 0,
		)));
		if ($catalogItemIds === [])
		{
			return;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$revisionColumn = $helper->quote(self::APP_CHAT_SYNC_REVISION_COLUMN);
		$pendingColumn = $helper->quote(self::APP_CHAT_SYNC_PENDING_COLUMN);

		$connection->queryExecute(
			'UPDATE ' . $helper->quote(CatalogItemTable::getTableName())
			. ' SET ' . $revisionColumn . ' = ' . $revisionColumn . ' + 1'
			. ', ' . $pendingColumn . ' = 1'
			. ' WHERE ' . $helper->quote('ID') . ' IN (' . implode(', ', $catalogItemIds) . ')',
		);
	}

	public function markAppChatSyncedRevision(int $catalogItemId, int $revision): void
	{
		if ($catalogItemId <= 0 || $revision <= 0)
		{
			return;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$syncedColumn = $helper->quote(self::APP_CHAT_SYNCED_REVISION_COLUMN);
		$requiredColumn = $helper->quote(self::APP_CHAT_SYNC_REVISION_COLUMN);
		$pendingColumn = $helper->quote(self::APP_CHAT_SYNC_PENDING_COLUMN);
		$newSynced = 'GREATEST(' . $syncedColumn . ', ' . $revision . ')';

		$connection->queryExecute(
			'UPDATE ' . $helper->quote(CatalogItemTable::getTableName())
			. ' SET ' . $syncedColumn . ' = ' . $newSynced
			. ', ' . $pendingColumn . ' = CASE WHEN ' . $requiredColumn . ' > ' . $newSynced
			. ' THEN 1 ELSE NULL END'
			. ' WHERE ' . $helper->quote('ID') . ' = ' . $catalogItemId,
		);
	}

	public function getIdsNeedingAppChatSync(int $afterId, int $limit): array
	{
		return $this->getIdsAfter(
			(new CatalogItemFilter())
				->type(CatalogItemType::Application)
				->needsAppChatSync()
				->includeDeactivated(),
			$afterId,
			$limit,
		);
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

	private function isFavoriteExpression(): ExpressionField
	{
		return new ExpressionField(
			'IS_FAVORITE',
			'CASE WHEN %s IS NOT NULL THEN 1 ELSE 0 END',
			['USER_FAVORITE.FAVORITED_AT'],
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
			->whereIn('ACCESS_CODE', $userCodes)
		;
	}

	public function filterGrantedItemIds(array $itemIds, array $userCodes): array
	{
		if ($itemIds === [] || $userCodes === [])
		{
			return [];
		}

		$rows = AccessTable::query()
			->setSelect(['CATALOG_ITEM_ID'])
			->whereIn('CATALOG_ITEM_ID', $itemIds)
			->whereIn('ACCESS_CODE', $userCodes)
			->setDistinct()
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['CATALOG_ITEM_ID'], $rows);
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
			->notViewedByUser(true)
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
					->whereIn('ID', $this->itemIdsGrantedAfter($userCodes, $baseline)),
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
			['*', 'IS_PINNED', 'IS_OWNED_BY_USER', 'IS_LAST_OPENED', 'IS_HIDDEN', 'IS_FAVORITE'],
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
			->where('GRANTED_AT', '>', $baseline)
		;
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
					'Failed to insert catalog item: ' . implode('; ', $result->getErrorMessages()),
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
				'Failed to update catalog item: ' . implode('; ', $result->getErrorMessages()),
			);
		}
	}

	public function compareAndSetAppChat(int $catalogItemId, ?int $expectedChatId, ?int $chatId): bool
	{
		return $this->compareAndSetAppChatWithResult($catalogItemId, $expectedChatId, $chatId)->succeeded;
	}

	public function compareAndSetAppChatWithResult(
		int $catalogItemId,
		?int $expectedChatId,
		?int $chatId,
	): CompareAndSetAppChatResult
	{
		return $this->compareAndSetAppChatWithResultInternal(
			$catalogItemId,
			$expectedChatId,
			$chatId,
			false,
		);
	}

	public function compareAndSetAppChatAndBumpSyncRevisionWithResult(
		int $catalogItemId,
		?int $expectedChatId,
		?int $chatId,
	): CompareAndSetAppChatResult
	{
		return $this->compareAndSetAppChatWithResultInternal(
			$catalogItemId,
			$expectedChatId,
			$chatId,
			true,
		);
	}

	private function compareAndSetAppChatWithResultInternal(
		int $catalogItemId,
		?int $expectedChatId,
		?int $chatId,
		bool $bumpSyncRevision,
	): CompareAndSetAppChatResult
	{
		if ($catalogItemId <= 0)
		{
			return new CompareAndSetAppChatResult(false, false);
		}

		$next = ($chatId ?? 0) > 0 ? (int)$chatId : null;
		$expected = ($expectedChatId ?? 0) > 0 ? (int)$expectedChatId : null;

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$column = $helper->quote(self::APP_CHAT_ID_COLUMN);
		$nextSql = $next === null ? 'NULL' : (string)$next;
		$welcomeColumn = $helper->quote(self::APP_CHAT_WELCOME_COLUMN);
		$set = $welcomeColumn . ' = CASE WHEN ' . $column . ' = ' . $nextSql
			. ' THEN ' . $welcomeColumn . ' ELSE NULL END'
			. ', ' . $column . ' = ' . $nextSql
			. ', ' . $helper->quote(self::APP_CHAT_TEARDOWN_PENDING_COLUMN)
			. ' = CASE WHEN ' . $helper->quote('DEACTIVATED_AT') . ' IS NOT NULL'
			. ' AND ' . $nextSql . ' > 0 THEN 1 ELSE NULL END'
		;
		if ($bumpSyncRevision)
		{
			$revisionColumn = $helper->quote(self::APP_CHAT_SYNC_REVISION_COLUMN);
			$set .= ', ' . $revisionColumn . ' = ' . $revisionColumn . ' + 1'
				. ', ' . $helper->quote(self::APP_CHAT_SYNC_PENDING_COLUMN) . ' = 1'
			;
		}
		$set .= ', ' . $helper->quote('UPDATED_AT') . ' = ' . $helper->convertToDbDateTime(new DateTime());

		$connection->queryExecute(
			'UPDATE ' . $helper->quote(CatalogItemTable::getTableName())
			. ' SET ' . $set
			. ' WHERE ' . $helper->quote('ID') . ' = ' . $catalogItemId
			. ' AND ' . ($expected === null
				? '(' . $column . ' IS NULL OR ' . $column . ' = 0)'
				: $column . ' = ' . $expected)
			. ($next === null ? '' : ' AND ' . $helper->quote('DEACTIVATED_AT') . ' IS NULL')
			. ($next === null ? '' : ' AND ' . $helper->quote('TYPE') . " = 'application'")
			. ($bumpSyncRevision
				? ' AND ' . ($next === null
					? $column . ' IS NOT NULL AND ' . $column . ' <> 0'
					: '(' . $column . ' IS NULL OR ' . $column . ' <> ' . $next . ')')
				: ''),
		);
		if ($connection->getAffectedRowsCount() > 0)
		{
			return new CompareAndSetAppChatResult(true, true);
		}

		return new CompareAndSetAppChatResult(
			$this->readAppChatId($catalogItemId) === ($next ?? 0),
			false,
		);
	}

	/**
	 * @return int|null null when the item is not bound to this chat; 0 when no welcome is recorded,
	 *                  a positive message id, or a negative claim holding the claim time.
	 */
	public function getAppChatWelcomeMark(int $catalogItemId, int $chatId): ?int
	{
		if ($catalogItemId <= 0 || $chatId <= 0)
		{
			return null;
		}

		$row = CatalogItemTable::query()
			->setSelect([self::APP_CHAT_WELCOME_COLUMN])
			->where('ID', $catalogItemId)
			->where(self::APP_CHAT_ID_COLUMN, $chatId)
			->fetch()
		;

		return $row === false ? null : (int)$row[self::APP_CHAT_WELCOME_COLUMN];
	}

	/**
	 * @param int $expectedMark 0 matches an empty mark.
	 * @param int $mark 0 clears the mark.
	 */
	public function compareAndSetAppChatWelcomeMark(
		int $catalogItemId,
		int $chatId,
		int $expectedMark,
		int $mark,
	): bool
	{
		if ($catalogItemId <= 0 || $chatId <= 0)
		{
			return false;
		}

		$filter = [
			'=ID' => $catalogItemId,
			'=' . self::APP_CHAT_ID_COLUMN => $chatId,
		];
		if ($expectedMark === 0)
		{
			$filter[] = [
				'LOGIC' => 'OR',
				['=' . self::APP_CHAT_WELCOME_COLUMN => null],
				['=' . self::APP_CHAT_WELCOME_COLUMN => 0],
			];
		}
		else
		{
			$filter['=' . self::APP_CHAT_WELCOME_COLUMN] = $expectedMark;
		}

		$entity = CatalogItemTable::getEntity();
		$connection = $entity->getConnection();
		$helper = $connection->getSqlHelper();
		[$set] = $helper->prepareUpdate(
			CatalogItemTable::getTableName(),
			[self::APP_CHAT_WELCOME_COLUMN => $mark === 0 ? null : $mark],
		);
		$connection->queryExecute(
			'UPDATE ' . $helper->quote(CatalogItemTable::getTableName())
			. ' SET ' . $set
			. ' WHERE ' . Query::buildFilterSql($entity, $filter),
		);

		return $connection->getAffectedRowsCount() > 0;
	}

	private function readAppChatId(int $catalogItemId): int
	{
		$row = CatalogItemTable::query()
			->setSelect([self::APP_CHAT_ID_COLUMN])
			->where('ID', $catalogItemId)
			->fetch()
		;

		return $row === false ? 0 : (int)$row[self::APP_CHAT_ID_COLUMN];
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
			. ', ' . $helper->quote(self::APP_CHAT_TEARDOWN_PENDING_COLUMN)
			. ' = CASE WHEN ' . $helper->quote(self::APP_CHAT_ID_COLUMN) . ' > 0 THEN 1 ELSE NULL END'
			. ', ' . $helper->quote('UPDATED_AT') . ' = ' . $datetime
			. ' WHERE ' . $helper->quote('PAIRING_ISS') . " = '" . $helper->forSql($iss) . "'"
			. ' AND ' . $helper->quote('DEACTIVATED_AT') . ' IS NULL',
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
