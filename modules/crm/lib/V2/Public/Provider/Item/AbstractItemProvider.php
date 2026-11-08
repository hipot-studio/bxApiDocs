<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Item;

use Bitrix\Crm\Category\PermissionEntityTypeHelper;
use Bitrix\Crm\Item as LegacyItem;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\ParentFieldManager;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Public\EntityTypeSettings;
use Bitrix\Crm\V2\Internal\Repository\Item\ItemRepositoryInterface;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldDescriptor;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldRegistry;
use Bitrix\Crm\V2\Internal\Service\Item\FieldRegistry;
use Bitrix\Crm\V2\Internal\Service\Item\FieldType;
use Bitrix\Crm\V2\Internal\Service\Item\Mapper\ItemFieldMapper;
use Bitrix\Crm\V2\Internal\Service\Item\ResolvedSelect;
use Bitrix\Crm\V2\Internal\Service\ItemCache;
use Bitrix\Crm\V2\Internal\Service\Loader\CompanyBindingLoader;
use Bitrix\Crm\V2\Internal\Service\Loader\ContactBindingLoader;
use Bitrix\Crm\V2\Internal\Service\Loader\CustomFieldDataLoader;
use Bitrix\Crm\V2\Internal\Service\Loader\DictionaryLoader;
use Bitrix\Crm\V2\Internal\Service\Loader\EmployeeLoader;
use Bitrix\Crm\V2\Internal\Service\Loader\ItemLoader;
use Bitrix\Crm\V2\Internal\Service\Loader\LastCommunicationLoader;
use Bitrix\Crm\V2\Internal\Service\Loader\MultifieldLoader;
use Bitrix\Crm\V2\Internal\Service\Loader\ObserverLoader;
use Bitrix\Crm\V2\Internal\Service\Loader\ParentLoader;
use Bitrix\Crm\V2\Internal\Service\Loader\ProductRowLoader;
use Bitrix\Crm\V2\Internal\Service\Loader\TrackingSourceLoader;
use Bitrix\Crm\V2\Internal\Service\Loader\UtmLoader;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasCategoriesInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\Entity\Item\ItemCollection;
use Bitrix\Crm\V2\Public\Entity\Item\ItemFactory;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Crm\V2\Public\Provider\Item\Exception\UnknownFilterFieldException;
use Bitrix\Crm\V2\Public\Provider\Item\Exception\UnknownSelectFieldException;
use Bitrix\Crm\V2\Public\Provider\Item\Exception\UnknownSortFieldException;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemFilter;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSort;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Query\Filter\Condition;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Provider\Params\PagerInterface;

/**
 * Read-side public API for CRM Item entities. Subclasses fix EntityType and Repository.
 *
 * `$select` is mandatory for every read that returns Items — callers say what they need.
 * Both an {@see ItemSelect} and a plain camelCase `string[]` are accepted (the latter is a
 * shorthand for `new ItemSelect(...$camelNames)`); use {@see ItemSelect::all()} to fetch every
 * standard scalar.
 */
abstract class AbstractItemProvider
{
	/**
	 * Filter key for "filter by parent": the value is the parent {@see ItemId}, whose type selects
	 * the `PARENT_ID_<typeId>` column and whose id is the compared value.
	 */
	public const PARENT_FILTER_KEY = 'parentId';

	private ?ItemRepositoryInterface $repository = null;

	private ?CacheMode $cacheMode = null;
	private ?AccessMode $accessMode = null;
	private ?int $accessUserId = null;

	/** @var array<int, EntityType>|null memoized - parent entity types (parentTypeId => EntityType) */
	private ?array $resolvedParentEntityTypes = null;

	private bool $parentReferencesEnsured = false;

	abstract protected function getEntityType(): EntityType;

	abstract protected function createRepository(): ItemRepositoryInterface;

	/**
	 * Returns a configured copy that serves reads through the shared {@see ItemCache}
	 * ({@see CacheMode::Runtime}: in-memory, current request only).
	 *
	 * `getById()` / `getByIds()` return cached items when every requested scalar field is
	 * already loaded, and fetch only the missing fields / missing ids otherwise (one query
	 * per read, merged into the cached snapshot). `getList()` only fills the cache — a filter
	 * cannot be resolved against it. `getCount()` bypasses the cache entirely.
	 *
	 * The cache stores raw, pre-access-check data: {@see withAccessCheck()} is applied on top
	 * of the cache at return time, so the same entry can be served in full to one user and as
	 * a restricted stub to another. Entries are invalidated point-wise on writes (V2 command
	 * handlers and legacy Operations).
	 */
	public function cached(CacheMode $mode = CacheMode::Runtime): static
	{
		$clone = clone $this;
		$clone->cacheMode = $mode;

		return $clone;
	}

	/**
	 * Returns a configured copy that runs every read through CRM read permissions.
	 *
	 * - {@see AccessMode::Filter} (default) — items the user can't read are excluded.
	 *   `getById()` returns `null`; `getList()` filters at the DB level so LIMIT/OFFSET act
	 *   on the visible subset, mirroring {@see \Bitrix\Crm\Service\Factory::getItemsFilteredByPermissions()}.
	 * - {@see AccessMode::Restricted} — unreadable items are still returned, but as data-less
	 *   stubs: only the id survives, `canRead()` is `false`, scalar accessors yield nothing and
	 *   relations are not loaded, so no protected data leaks. Readable items are returned in
	 *   full. Use this for cards / timelines / kanbans that need a placeholder row.
	 *
	 * Anonymous user (`userId === 0`): every item is denied — `Filter` returns empty,
	 * `Restricted` returns every item as a data-less stub.
	 */
	public function withAccessCheck(
		?int $userId = null,
		AccessMode $mode = AccessMode::Filter,
	): static
	{
		$clone = clone $this;
		$clone->accessMode = $mode;
		$clone->accessUserId = $userId;

		return $clone;
	}

	/**
	 * @param ItemSelect|string[] $select Required. Accept either an {@see ItemSelect} or a plain
	 *                                    camelCase string[] (treated as `new ItemSelect(...$names)`).
	 */
	public function getById(int $id, ItemSelect|array $select): ?Item
	{
		$select = ItemSelect::from($select);

		if ($this->cacheMode !== null)
		{
			$items = $this->readThroughCache([$id], $select);

			return $items[0] ?? null;
		}

		// One hidden-UF memo for the whole operation - see lazyHiddenCustomFieldNames().
		$hiddenCustomFields = $this->lazyHiddenCustomFieldNames();
		$resolved = $this->resolveSelect($select, $hiddenCustomFields);
		$ormFields = $resolved->ormNames;
		if ($this->accessMode !== null)
		{
			$ormFields = $this->ensureCategoryFieldForAccessCheck($ormFields);
		}

		$entityObject = $this->getRepository()->getById($id, $ormFields);
		if ($entityObject === null)
		{
			return null;
		}

		$item = ItemFactory::create($this->getEntityType());
		ItemFieldMapper::syncFromOrm(
			$entityObject,
			$item,
			FieldRegistry::getInstance($this->getEntityType()),
			$ormFields,
		);
		ItemFieldMapper::syncCustomFieldsFromOrm(
			$entityObject,
			$item,
			$resolved->ufNames,
			$this->customFieldDescriptorsFor($resolved->ufNames),
		);

		// Access check runs before relation loading so heavy relations load only when readable.
		if ($this->accessMode !== null && !$this->canReadItem($item))
		{
			return $this->accessMode === AccessMode::Restricted
				? $this->toRestrictedStub($item)
				: null
			;
		}

		$this->applyLoaders([$item], $select);
		$this->applyTaskLoaders([$item], $select);
		$this->applyCustomFieldVisibility([$item], $hiddenCustomFields);

		return $item;
	}

	/**
	 * @param int[] $ids
	 * @param ItemSelect|string[] $select Required.
	 */
	public function getByIds(array $ids, ItemSelect|array $select): ItemCollection
	{
		$collection = new ItemCollection();
		if (empty($ids))
		{
			return $collection;
		}

		$select = ItemSelect::from($select);

		if ($this->cacheMode !== null)
		{
			foreach ($this->readThroughCache(array_values(array_unique($ids)), $select) as $item)
			{
				$collection->add($item);
			}

			return $collection;
		}

		// One hidden-UF memo for the whole operation - see lazyHiddenCustomFieldNames().
		$hiddenCustomFields = $this->lazyHiddenCustomFieldNames();
		$resolved = $this->resolveSelect($select, $hiddenCustomFields);
		$ormFields = $resolved->ormNames;
		if ($this->accessMode !== null)
		{
			$ormFields = $this->ensureCategoryFieldForAccessCheck($ormFields);
		}

		$entityType = $this->getEntityType();
		$ufNames = $resolved->ufNames;
		// Batch-wide metadata: resolved once, not per item.
		$registry = FieldRegistry::getInstance($entityType);
		$ufDescriptors = $this->customFieldDescriptorsFor($ufNames);
		$items = [];
		foreach ($this->getRepository()->getByIds($ids, $ormFields) as $entityObject)
		{
			$item = ItemFactory::create($entityType);
			ItemFieldMapper::syncFromOrm($entityObject, $item, $registry, $ormFields);
			ItemFieldMapper::syncCustomFieldsFromOrm($entityObject, $item, $ufNames, $ufDescriptors);
			$items[] = $item;
		}

		if (empty($items))
		{
			return $collection;
		}

		// Access check runs before relation loading so heavy relations load only for readable items.
		[$kept, $loadable] = $this->partitionByAccess($items);
		if (!empty($loadable))
		{
			$this->applyLoaders($loadable, $select);
			$this->applyTaskLoaders($loadable, $select);
		}
		$this->applyCustomFieldVisibility($kept, $hiddenCustomFields);
		foreach ($kept as $item)
		{
			$collection->add($item);
		}

		return $collection;
	}

	/**
	 * Reads a list of Items.
	 *
	 * Example — open deals in stage NEW with `opportunity >= 100`, sorted, paginated, with
	 * `productRows` eagerly loaded:
	 *
	 * ```php
	 * use Bitrix\Crm\V2\Public\Provider\Item\DealProvider;
	 * use Bitrix\Crm\V2\Public\Provider\Item\Param\{ItemFilter, ItemSelect, ItemSort};
	 * use Bitrix\Main\Provider\Params\Pager;
	 *
	 * $deals = (new DealProvider())->getList(
	 *     (new ItemSelect('title', 'opportunity'))->withProductRows(),
	 *     new ItemFilter([
	 *         'stageId'       => 'NEW',
	 *         '>=opportunity' => 100,
	 *         '@assignedById' => [1, 2, 3],
	 *     ]),
	 *     new ItemSort(['createdTime' => 'DESC']),
	 *     new Pager(limit: 25),
	 * );
	 * ```
	 *
	 * `$select` is required; pass {@see ItemSelect::all()} to read every scalar (avoid it for
	 * performance). A plain camelCase `string[]` is accepted as a shorthand. Without a `$pager`
	 * the result is unbounded.
	 *
	 * @param ItemSelect|string[] $select
	 */
	public function getList(
		ItemSelect|array $select,
		?ItemFilter $filter = null,
		?ItemSort $sort = null,
		?PagerInterface $pager = null,
	): ItemCollection
	{
		$select = ItemSelect::from($select);

		// Parent columns (PARENT_ID_<typeId>) must be registered on the ORM entity before the filter
		// and order that reference them are built and handed to the repository.
		$this->ensureParentFieldReferences();

		$entityType = $this->getEntityType();
		// One hidden-UF memo for the whole operation - see lazyHiddenCustomFieldNames().
		$hiddenCustomFields = $this->lazyHiddenCustomFieldNames();
		$resolved = $this->resolveSelect($select, $hiddenCustomFields);
		$ormFields = $resolved->ormNames;
		$ormFilter = $this->resolveFilter($filter, $hiddenCustomFields);
		$order = $this->resolveOrmOrder($sort, $hiddenCustomFields);
		$runtime = null;

		if ($this->accessMode === AccessMode::Filter)
		{
			[$ormFields, $ormFilter, $runtime, $denied] = $this->applyPermissionFilter($ormFields, $ormFilter);
			if ($denied)
			{
				return new ItemCollection();
			}
		}
		elseif ($this->accessMode === AccessMode::Restricted)
		{
			// Restricted keeps every row but hides protected data; per-item checks need the category.
			$ormFields = $this->ensureCategoryFieldForAccessCheck($ormFields);
		}

		$ufNames = $resolved->ufNames;
		// Batch-wide metadata: resolved once, not per item.
		$registry = FieldRegistry::getInstance($entityType);
		$ufDescriptors = $this->customFieldDescriptorsFor($ufNames);
		$collection = new ItemCollection();
		$items = [];
		$rows = $this->getRepository()->getList(
			$ormFields,
			$ormFilter,
			$order,
			$pager?->getLimit(),
			$pager?->getOffset(),
			$runtime,
		);
		foreach ($rows as $entityObject)
		{
			$item = ItemFactory::create($entityType);
			ItemFieldMapper::syncFromOrm($entityObject, $item, $registry, $ormFields);
			ItemFieldMapper::syncCustomFieldsFromOrm($entityObject, $item, $ufNames, $ufDescriptors);
			$items[] = $item;
		}

		if (empty($items))
		{
			return $collection;
		}

		// Filter mode already restricted at the DB level; only Restricted mode post-processes rows.
		$kept = $items;
		$loadable = $items;
		if ($this->accessMode === AccessMode::Restricted)
		{
			[$kept, $loadable] = $this->partitionByAccess($items);
		}

		if (!empty($loadable))
		{
			$this->applyLoaders($loadable, $select);
		}

		if ($this->cacheMode !== null)
		{
			$this->fillCacheFromList($items, $ormFields, $ufNames, $select);
		}

		if (!empty($loadable))
		{
			$this->applyTaskLoaders($loadable, $select);
		}

		// After the raw rows are cached: strip UF the access user can't see from the copies returned.
		$this->applyCustomFieldVisibility($kept, $hiddenCustomFields);
		foreach ($kept as $item)
		{
			$collection->add($item);
		}

		return $collection;
	}

	public function getCount(?ItemFilter $filter = null): int
	{
		$this->ensureParentFieldReferences();

		$ormFilter = $this->resolveFilter($filter);
		$runtime = null;

		// Mirror getList: only Filter mode narrows the result set. Restricted mode keeps every
		// row (returned as stubs), so it must not affect the count.
		if ($this->accessMode === AccessMode::Filter)
		{
			[, $ormFilter, $runtime, $denied] = $this->applyPermissionFilter(['ID'], $ormFilter);
			if ($denied)
			{
				return 0;
			}
		}

		return $this->getRepository()->getCount($ormFilter, $runtime);
	}

	/**
	 * Builds an ID-only query through the same field-resolution and access-check path as getList().
	 */
	public function createIdQuery(?ItemFilter $filter = null): ?Query
	{
		$this->ensureParentFieldReferences();

		$ormFilter = $this->resolveFilter($filter);
		$runtime = null;
		if ($this->accessMode === AccessMode::Filter)
		{
			[, $ormFilter, $runtime, $denied] = $this->applyPermissionFilter(['ID'], $ormFilter);
			if ($denied)
			{
				$ormFilter = (new ConditionTree())->where('ID', 0);
			}
		}

		return $this->getRepository()->createIdQuery($ormFilter, $runtime);
	}

	final protected function getRepository(): ItemRepositoryInterface
	{
		if ($this->repository === null)
		{
			$this->repository = $this->createRepository();
		}

		return $this->repository;
	}

	/**
	 * Cache-aware read core behind `getById()` / `getByIds()`.
	 *
	 * Serves items from {@see ItemCache} when every requested scalar is already loaded there;
	 * everything else — full misses and the missing fields of partial hits — is fetched from
	 * the repository in a single query and merged into the cached snapshots (already-loaded
	 * values are never overwritten, and the merge leaves no change marks). Relations are
	 * loaded only where absent on the merged item. Snapshots are cached raw, before the
	 * access check; restricted stubs are never cached.
	 *
	 * @param int[] $ids unique ids
	 * @return Item[] kept items in input order (missing rows and Filter-mode denials are skipped)
	 */
	private function readThroughCache(array $ids, ItemSelect $select): array
	{
		$cache = ItemCache::getInstance();
		$entityType = $this->getEntityType();

		// One hidden-UF memo for the whole operation - see lazyHiddenCustomFieldNames().
		$hiddenCustomFields = $this->lazyHiddenCustomFieldNames();
		$resolved = $this->resolveSelect($select, $hiddenCustomFields);
		$ormFields = $resolved->ormNames;
		if ($this->accessMode !== null)
		{
			$ormFields = $this->ensureCategoryFieldForAccessCheck($ormFields);
		}
		$ufNames = $resolved->ufNames;
		// Batch-wide metadata: resolved once, not per item.
		$registry = FieldRegistry::getInstance($entityType);
		$ufDescriptors = $this->customFieldDescriptorsFor($ufNames);
		$requestAllUf = $select->shouldLoadAllCustomFields();
		$requestAllScalars = $select->isAll();
		$requestParents = $select->shouldLoadParents();
		// UF loadedness is tracked on the cache entry (not on the Item), so keep scalar ORM columns
		// and UF names apart for the per-field cache diff below. This concrete list is bookkeeping
		// only - the DB fetch selects '*' under a wildcard request (see below).
		$requestedOrm = $this->expandWildcardOrmFields($ormFields);
		$requestedScalarOrm = array_values(array_diff($requestedOrm, $ufNames));

		/** @var array<int, Item> $rawById */
		$rawById = [];
		/** @var array<int, string[]> $loadedScalarById */
		$loadedScalarById = [];
		/** @var array<int, string[]> $loadedUfById */
		$loadedUfById = [];
		/** @var array<int, bool> $allUfLoadedById */
		$allUfLoadedById = [];
		/** @var array<int, bool> $parentsLoadedById parent loadedness - tracked on the cache entry */
		$parentsLoadedById = [];
		/** @var array<int, bool> $dirtyById */
		$dirtyById = [];
		/** @var array<int, string[]> $missScalarById id => scalar ORM columns to fetch */
		$missScalarById = [];
		/** @var array<int, string[]> $missUfById id => UF names to fetch */
		$missUfById = [];

		foreach ($ids as $id)
		{
			$loadedScalar = $cache->getLoadedOrmFields($entityType, $id);
			$item = $loadedScalar !== null ? $cache->get($entityType, $id) : null;
			if ($item === null)
			{
				$missScalarById[$id] = $requestedScalarOrm;
				$missUfById[$id] = $ufNames;
				$parentsLoadedById[$id] = false;

				continue;
			}

			$rawById[$id] = $item;
			$loadedScalarById[$id] = $loadedScalar;
			$loadedUfById[$id] = $cache->getLoadedCustomFields($entityType, $id) ?? [];
			$allUfLoadedById[$id] = $cache->areAllCustomFieldsLoaded($entityType, $id);
			$parentsLoadedById[$id] = $cache->areParentsLoaded($entityType, $id);
			$dirtyById[$id] = false;

			$missScalar = array_values(array_diff($requestedScalarOrm, $loadedScalar));
			if (!empty($missScalar))
			{
				$missScalarById[$id] = $missScalar;
			}

			// A snapshot with every UF covers any UF subset - nothing to fetch for it.
			$missUf = $allUfLoadedById[$id] ? [] : array_values(array_diff($ufNames, $loadedUfById[$id]));
			if (!empty($missUf))
			{
				$missUfById[$id] = $missUf;
			}
		}

		$fetchIds = array_values(array_unique(
			array_merge(array_keys($missScalarById), array_keys($missUfById)),
		));
		if (!empty($fetchIds))
		{
			// A single-item read fetches only that item's missing columns / UF; a batch fetches the
			// requested set in one query and merges per item strictly by its own missing set, so
			// loaded values are never clobbered either way. Under a wildcard request the scalar side
			// of the select is the '*' token (the concrete FieldRegistry expansion is bookkeeping only)
			// - matching the non-cache path; UF names are always selected explicitly so they are never
			// lazy-loaded.
			if (count($fetchIds) === 1)
			{
				$onlyId = $fetchIds[0];
				$scalarSelect = $requestAllScalars ? ['*'] : ($missScalarById[$onlyId] ?? []);
				$fetchSelect = array_values(array_unique(array_merge(
					['ID'],
					$scalarSelect,
					$missUfById[$onlyId] ?? [],
				)));
			}
			else
			{
				$scalarSelect = $requestAllScalars ? ['*'] : $requestedScalarOrm;
				$fetchSelect = array_values(array_unique(array_merge($scalarSelect, $ufNames)));
			}

			$foundIds = [];
			foreach ($this->getRepository()->getByIds($fetchIds, $fetchSelect) as $entityObject)
			{
				$id = (int)$entityObject->get('ID');
				$foundIds[$id] = true;

				$item = $rawById[$id] ?? null;
				if ($item === null)
				{
					$item = ItemFactory::create($entityType);
					ItemFieldMapper::syncFromOrm($entityObject, $item, $registry, $requestedScalarOrm);
					ItemFieldMapper::syncCustomFieldsFromOrm($entityObject, $item, $ufNames, $ufDescriptors);
					$rawById[$id] = $item;
					$loadedScalarById[$id] = $requestedScalarOrm;
					$loadedUfById[$id] = $ufNames;
					$allUfLoadedById[$id] = $requestAllUf;
				}
				else
				{
					ItemFieldMapper::syncFromOrm($entityObject, $item, $registry, $missScalarById[$id] ?? []);
					ItemFieldMapper::syncCustomFieldsFromOrm(
						$entityObject,
						$item,
						$missUfById[$id] ?? [],
						$ufDescriptors,
					);
					$loadedScalarById[$id] = array_values(array_unique(
						array_merge($loadedScalarById[$id], $missScalarById[$id] ?? []),
					));
					$loadedUfById[$id] = array_values(array_unique(
						array_merge($loadedUfById[$id], $missUfById[$id] ?? []),
					));
					$allUfLoadedById[$id] = $allUfLoadedById[$id] || $requestAllUf;
				}

				$dirtyById[$id] = true;
			}

			// The row is gone from the DB — drop the stale entry instead of serving a deleted item.
			foreach ($fetchIds as $id)
			{
				if (!isset($foundIds[$id]) && isset($rawById[$id]))
				{
					$cache->invalidate($entityType, $id);
					unset(
						$rawById[$id],
						$loadedScalarById[$id],
						$loadedUfById[$id],
						$allUfLoadedById[$id],
						$parentsLoadedById[$id],
						$dirtyById[$id],
					);
				}
			}
		}

		if (empty($rawById))
		{
			return [];
		}

		// Access check runs before relation loading so heavy relations load only when readable.
		if ($this->accessMode !== null)
		{
			$this->preloadPermissions(array_values($rawById));
		}

		$kept = [];
		$loadable = [];
		foreach ($ids as $id)
		{
			$item = $rawById[$id] ?? null;
			if ($item === null)
			{
				continue;
			}

			if ($this->accessMode === null || $this->canReadItem($item))
			{
				$kept[] = $item;
				$loadable[] = $item;

				continue;
			}

			if ($this->accessMode === AccessMode::Restricted)
			{
				$kept[] = $this->toRestrictedStub($item);
			}
			// AccessMode::Filter: drop the unreadable item entirely.
		}

		$gotRelations = $this->loadMissingRelations($loadable, $select);

		// Parents are tracked on the cache entry (the Item's parentIds bag is never null, so it can't
		// signal "not loaded"). Fetch them only for readable items whose snapshot lacks parents, in a
		// single batch, and mark those items for re-cache.
		if ($requestParents)
		{
			$needParents = array_values(array_filter(
				$loadable,
				static fn(Item $item): bool => !($parentsLoadedById[(int)$item->getId()] ?? false),
			));
			if (!empty($needParents))
			{
				(new ParentLoader())->load($needParents, $select);
				foreach ($needParents as $item)
				{
					$parentsLoadedById[(int)$item->getId()] = true;
					$gotRelations[spl_object_id($item)] = true;
				}
			}
		}

		// Persist raw snapshots: merged scalars/UF and freshly loaded relations. Untouched full
		// hits are skipped — re-cloning an identical snapshot buys nothing.
		foreach ($rawById as $id => $item)
		{
			if ($dirtyById[$id] || isset($gotRelations[spl_object_id($item)]))
			{
				$cache->set(
					$entityType,
					$id,
					$item,
					$loadedScalarById[$id],
					$loadedUfById[$id],
					$allUfLoadedById[$id],
					$parentsLoadedById[$id] ?? false,
				);
			}
		}

		$this->applyTaskLoaders($loadable, $select);

		// Per-user UF visibility is applied only now - after the raw snapshots are cached - on the
		// readable copies handed back. Restricted stubs carry no UF.
		$this->applyCustomFieldVisibility($kept, $hiddenCustomFields);

		return $kept;
	}

	/**
	 * Loads only the requested relations that are absent on each item (`null` collection =
	 * "not loaded"), grouping items per relation so an already-loaded collection is never
	 * re-fetched or replaced.
	 *
	 * @param Item[] $items
	 * @return array<int, true> `spl_object_id` set of items that received at least one relation
	 */
	private function loadMissingRelations(array $items, ItemSelect $select): array
	{
		if (empty($items))
		{
			return [];
		}

		$touched = [];

		if ($select->shouldLoadPhones())
		{
			$this->loadRelationWhereMissing($items, 'getPhones', static fn(ItemSelect $s) => $s->withPhones(), new MultifieldLoader(), $touched);
		}
		if ($select->shouldLoadEmails())
		{
			$this->loadRelationWhereMissing($items, 'getEmails', static fn(ItemSelect $s) => $s->withEmails(), new MultifieldLoader(), $touched);
		}
		if ($select->shouldLoadWebs())
		{
			$this->loadRelationWhereMissing($items, 'getWebs', static fn(ItemSelect $s) => $s->withWebs(), new MultifieldLoader(), $touched);
		}
		if ($select->shouldLoadIms())
		{
			$this->loadRelationWhereMissing($items, 'getIms', static fn(ItemSelect $s) => $s->withIms(), new MultifieldLoader(), $touched);
		}
		if ($select->shouldLoadProductRows())
		{
			$this->loadRelationWhereMissing($items, 'getProductRows', static fn(ItemSelect $s) => $s->withProductRows(), new ProductRowLoader(), $touched);
		}
		if ($select->shouldLoadContactBindings())
		{
			$this->loadRelationWhereMissing($items, 'getContactBindings', static fn(ItemSelect $s) => $s->withContactBindings(), new ContactBindingLoader(), $touched);
		}
		if ($select->shouldLoadCompanyBindings())
		{
			$this->loadRelationWhereMissing($items, 'getCompanyBindings', static fn(ItemSelect $s) => $s->withCompanyBindings(), new CompanyBindingLoader(), $touched);
		}
		if ($select->shouldLoadObservers())
		{
			$this->loadRelationWhereMissing($items, 'getObservers', static fn(ItemSelect $s) => $s->withObservers(), new ObserverLoader(), $touched);
		}

		return $touched;
	}

	/**
	 * Runs $loader for the subset of $items on which the relation behind $getter is not loaded
	 * yet (or the entity type lacks it altogether — such items are skipped).
	 *
	 * @param Item[] $items
	 * @param callable(ItemSelect): ItemSelect $enableRelation
	 * @param object $loader any `Internal\Service\Loader\*` with `load(Item[], ItemSelect)`
	 * @param array<int, true> $touched in-out `spl_object_id` set of items that received data
	 */
	private function loadRelationWhereMissing(
		array $items,
		string $getter,
		callable $enableRelation,
		object $loader,
		array &$touched,
	): void
	{
		$subset = array_values(array_filter(
			$items,
			static fn(Item $item): bool => method_exists($item, $getter) && $item->$getter() === null,
		));
		if (empty($subset))
		{
			return;
		}

		$loader->load($subset, $enableRelation(new ItemSelect('id')));

		foreach ($subset as $item)
		{
			$touched[spl_object_id($item)] = true;
		}
	}

	/**
	 * `getList()` only fills the cache — its filter cannot be resolved against cached entries.
	 * A list row never downgrades an existing entry: when a relation-less row is fully covered
	 * by what's already cached, the richer snapshot is kept.
	 *
	 * @param Item[] $items raw hydrated rows (pre access post-processing)
	 * @param string[] $ormFields ORM select the rows were actually read with
	 * @param string[] $ufNames custom-field names the rows were hydrated with
	 */
	private function fillCacheFromList(
		array $items,
		array $ormFields,
		array $ufNames,
		ItemSelect $select,
	): void
	{
		$cache = ItemCache::getInstance();
		$entityType = $this->getEntityType();
		$loadedOrm = $this->expandWildcardOrmFields($ormFields);
		$allUf = $select->shouldLoadAllCustomFields();
		$parentsLoaded = $select->shouldLoadParents();
		// UF are tracked apart from scalar ORM columns on the cache entry.
		$scalarOrm = array_values(array_diff($loadedOrm, $ufNames));

		$carriesRelations = $select->shouldLoadAnyMultifield()
			|| $select->shouldLoadProductRows()
			|| $select->shouldLoadContactBindings()
			|| $select->shouldLoadCompanyBindings()
			|| $select->shouldLoadObservers()
			|| $parentsLoaded;

		foreach ($items as $item)
		{
			$id = $item->getId();
			if ($id === null)
			{
				continue;
			}

			if (!$carriesRelations)
			{
				$cachedScalar = $cache->getLoadedOrmFields($entityType, $id);
				$scalarCovered = $cachedScalar !== null && empty(array_diff($scalarOrm, $cachedScalar));
				$ufCovered = $cache->areAllCustomFieldsLoaded($entityType, $id)
					|| (
						!$allUf
						&& empty(array_diff($ufNames, $cache->getLoadedCustomFields($entityType, $id) ?? []))
					)
				;
				if ($scalarCovered && $ufCovered)
				{
					continue;
				}
			}

			$cache->set($entityType, $id, $item, $scalarOrm, $ufNames, $allUf, $parentsLoaded);
		}
	}

	/**
	 * Replaces the `'*'` wildcard with the concrete list of scalar ORM columns known to the
	 * {@see FieldRegistry} — cache bookkeeping needs concrete names to compute per-field diffs.
	 *
	 * The result is used only for cache bookkeeping (per-field loadedness and diffs), never as a raw
	 * ORM select. The DB fetch itself keeps the `'*'` token (see {@see readThroughCache()}): a
	 * wildcard read selects every column rather than the enumerated set, so it never has to track
	 * which concrete columns the entity declares.
	 *
	 * @param string[] $ormFields
	 * @return string[]
	 */
	private function expandWildcardOrmFields(array $ormFields): array
	{
		if (!in_array('*', $ormFields, true))
		{
			return $ormFields;
		}

		$names = ['ID'];
		foreach (FieldRegistry::getInstance($this->getEntityType())->getFields() as $descriptor)
		{
			if (!self::isRelationFieldType($descriptor->type))
			{
				$names[] = $descriptor->ormName;
			}
		}

		return array_values(array_unique($names));
	}

	/**
	 * Resolves an {@see ItemSelect} once into the ORM columns to read and the custom-field (`UF_*`)
	 * names to hydrate.
	 *
	 * Custom fields resolve to their raw column name (ORM expands the UTS/UTM storage on its own);
	 * {@see ItemSelect::withCustomFields()} pulls in every UF of the type. An unknown name - neither
	 * a standard field nor a known UF - is a hard error (no silent drop).
	 *
	 * A name without the `UF_` prefix can never be a custom field, so it is rejected without asking
	 * the {@see CustomFieldRegistry}: the prefix only shortcuts the lookup, it never replaces the
	 * existence check.
	 *
	 * @param ?\Closure $hiddenCustomFields {@see lazyHiddenCustomFieldNames()} of the running operation.
	 * @throws UnknownSelectFieldException When a requested name is neither a standard field nor a known visible UF.
	 */
	private function resolveSelect(ItemSelect $select, ?\Closure $hiddenCustomFields = null): ResolvedSelect
	{
		$entityType = $this->getEntityType();
		$registry = FieldRegistry::getInstance($entityType);
		$customFields = CustomFieldRegistry::getInstance();
		$hiddenCustomFields ??= $this->lazyHiddenCustomFieldNames();

		// The scalar wildcard covers every standard scalar column; UF stay opt-in (explicit `UF_*`
		// entries or `withCustomFields()`) and are appended even under the wildcard - no silent drop.
		$isWildcard = $select->isAll();
		$ormNames = $isWildcard ? ['*'] : ['ID']; // ID is implicit for round-tripping
		$ufNames = [];

		if ($select->shouldLoadAllCustomFields())
		{
			foreach ($customFields->getCustomFieldNames($entityType) as $ufName)
			{
				$ormNames[] = $ufName;
				$ufNames[] = $ufName;
			}
		}

		foreach ($select->getFields() as $camelName)
		{
			if ($camelName === ItemSelect::WILDCARD)
			{
				continue;
			}

			$descriptor = $registry->getField($camelName);
			if ($descriptor !== null)
			{
				// Relation collections live in separate tables and are loaded by Loaders.
				if (self::isRelationFieldType($descriptor->type))
				{
					continue;
				}

				// A named standard scalar alongside the wildcard is already covered by `*`.
				if (!$isWildcard)
				{
					$ormNames[] = $descriptor->ormName;
				}

				continue;
			}

			if (!str_starts_with($camelName, 'UF_') || !$customFields->isCustomField($entityType, $camelName))
			{
				throw new UnknownSelectFieldException($camelName);
			}

			// Under an active access check a UF the user can't see is treated as unknown - existence
			// is not revealed.
			if (in_array($camelName, $hiddenCustomFields(), true))
			{
				throw new UnknownSelectFieldException($camelName);
			}

			$ormNames[] = $camelName;
			$ufNames[] = $camelName;
		}

		return new ResolvedSelect($ormNames, $ufNames);
	}

	/**
	 * Custom-field descriptors the Mapper needs for a read, resolved once per read instead of once
	 * per item. A read without custom fields gets an empty map and never asks the registry: building
	 * the map can cost a query, and that cost must stay tied to actually reading a `UF_*`.
	 *
	 * @param string[] $ufNames
	 * @return array<string, CustomFieldDescriptor>
	 */
	private function customFieldDescriptorsFor(array $ufNames): array
	{
		if (empty($ufNames))
		{
			return [];
		}

		return CustomFieldRegistry::getInstance()->getEntityDescriptorsMap($this->getEntityType());
	}

	/**
	 * Custom-field (`UF_*`) names to treat as non-existent for this read: under an active access
	 * check, UF the access user can't see are hidden from field resolution (so a select / filter /
	 * sort by one fails exactly like an unknown field) and stripped from returned items. Empty for
	 * a system read (no access check). Resolved on demand only - a read that never meets a custom
	 * field never asks the visibility subsystem.
	 *
	 * @return string[]
	 */
	private function hiddenCustomFieldNames(): array
	{
		if ($this->accessMode === null)
		{
			return [];
		}

		return CustomFieldRegistry::getInstance()
			->getHiddenFieldNames($this->getEntityType(), $this->accessUserId)
		;
	}

	/**
	 * A memo over {@see hiddenCustomFieldNames()}: the hidden set is computed on the first custom
	 * field met and reused afterwards. A read built only of standard fields never triggers the
	 * computation - the closure is created, never called.
	 *
	 * Created once per read operation and handed to every step that resolves names (select, filter,
	 * sort) and to the visibility pass, so one read computes the set once instead of up to four
	 * times. The memo lives in the closure, not on the provider: an instance is single-use and
	 * {@see withAccessCheck()} / {@see cached()} clone it, so instance state would be misleading.
	 *
	 * @return \Closure(): string[]
	 */
	private function lazyHiddenCustomFieldNames(): \Closure
	{
		$resolved = null;

		return function () use (&$resolved): array {
			return $resolved ??= $this->hiddenCustomFieldNames();
		};
	}

	/**
	 * Parent entity types declared for this entity type (`parentTypeId => EntityType`). Empty for
	 * entities without parent relations. Memoized - the map is user-independent and fixed per type.
	 *
	 * @return array<int, EntityType>
	 */
	private function parentEntityTypes(): array
	{
		return $this->resolvedParentEntityTypes ??=
			EntityTypeSettings::of($this->getEntityType())->getParentEntityTypesMap()
		;
	}

	/**
	 * Registers the `PARENT_ID_<parentTypeId>` reference/expression fields on the ORM entity so
	 * parent columns are addressable in filter / order. A no-op for entity types without parent
	 * relations; idempotent otherwise (the manager guards by `hasField`, and the result is memoized
	 * per provider instance).
	 */
	private function ensureParentFieldReferences(): void
	{
		if ($this->parentReferencesEnsured)
		{
			return;
		}
		$this->parentReferencesEnsured = true;

		if (empty($this->parentEntityTypes()))
		{
			return;
		}

		$this->getRepository()->ensureParentFieldReferences($this->getEntityType()->getId());
	}

	/**
	 * Applies per-user UF visibility to the items handed back: under an active access check, the
	 * custom fields the access user can't see are dropped from each readable item. A system read
	 * (no access check) exposes every UF. Must run on the copies returned to the caller, never on
	 * the raw cached snapshot - the cache keeps every UF regardless of who reads it.
	 *
	 * @param Item[] $items
	 * @param ?\Closure $hiddenCustomFields {@see lazyHiddenCustomFieldNames()} of the running operation.
	 */
	private function applyCustomFieldVisibility(array $items, ?\Closure $hiddenCustomFields = null): void
	{
		if ($this->accessMode === null)
		{
			return;
		}

		$hiddenCustomFields ??= $this->lazyHiddenCustomFieldNames();

		// The hidden set is asked for only once an item actually carries custom fields: a read
		// without them - and a Restricted stub, which carries none - has nothing to strip.
		$hidden = null;
		foreach ($items as $item)
		{
			if (!$item->canRead() || $item->getCustomFields() === [])
			{
				continue;
			}

			$hidden ??= $hiddenCustomFields();
			if ($hidden === [])
			{
				return;
			}

			$item->internalRemoveCustomFields($hidden);
		}
	}

	/**
	 * Resolves an {@see ItemFilter} to a repository-ready filter (`array` for the
	 * setFilter-style input, {@see ConditionTree} for the tree input). Field names in both
	 * forms are camelCase and translated to ORM column names here.
	 *
	 * @param ?\Closure $hiddenCustomFields {@see lazyHiddenCustomFieldNames()} of the running operation.
	 */
	private function resolveFilter(?ItemFilter $filter, ?\Closure $hiddenCustomFields = null): array|ConditionTree|null
	{
		if ($filter === null)
		{
			return null;
		}

		$raw = $filter->getRaw();
		$entityType = $this->getEntityType();
		$registry = FieldRegistry::getInstance($entityType);
		$hiddenCustomFields ??= $this->lazyHiddenCustomFieldNames();
		$parentTypes = $this->parentEntityTypes();

		if ($raw instanceof ConditionTree)
		{
			$cloned = clone $raw;
			self::translateTreeColumns($cloned, $registry, $entityType, $hiddenCustomFields, $parentTypes);

			return $cloned;
		}

		return self::translateArrayFilterKeys($raw, $registry, $entityType, $hiddenCustomFields, $parentTypes);
	}

	/**
	 * Resolves an {@see ItemSort} to an ORM-name keyed direction map.
	 *
	 * @param ?\Closure $hiddenCustomFields {@see lazyHiddenCustomFieldNames()} of the running operation.
	 * @return array<string, string>|null
	 */
	private function resolveOrmOrder(?ItemSort $sort, ?\Closure $hiddenCustomFields = null): ?array
	{
		if ($sort === null || $sort->isEmpty())
		{
			return null;
		}

		$entityType = $this->getEntityType();
		$registry = FieldRegistry::getInstance($entityType);
		$hiddenCustomFields ??= $this->lazyHiddenCustomFieldNames();
		$parentTypes = $this->parentEntityTypes();
		$result = [];
		foreach ($sort->getRaw() as $camelName => $direction)
		{
			$descriptor = $registry->getField($camelName);
			if ($descriptor !== null)
			{
				$ormName = $descriptor->ormName;
			}
			elseif (
				str_starts_with($camelName, 'UF_')
				&& CustomFieldRegistry::getInstance()->isCustomField($entityType, $camelName)
				&& !in_array($camelName, $hiddenCustomFields(), true)
			)
			{
				// A multiple UF orders through a row-multiplying JOIN to its UTM back-reference. The
				// two-stage read (fetchIds) applies LIMIT/OFFSET to those multiplied rows without a
				// DISTINCT/GROUP BY, so distinct primaries are undercounted and paging drifts. The
				// core's filter-only `_SINGLE` rewrite does not cover ORDER, so reject it up front.
				if (CustomFieldRegistry::getInstance()->isMultipleCustomField($entityType, $camelName))
				{
					throw new ArgumentException(
						'Sorting by multiple custom field is not supported: ' . $camelName,
						'order',
					);
				}

				// A known, single, visible UF sorts by its raw column name (ORM resolves storage);
				// a UF hidden from the access user is rejected like an unknown field.
				$ormName = $camelName;
			}
			elseif (ParentFieldManager::isParentFieldName($camelName))
			{
				// A parent field sorts by its canonical PARENT_ID_<typeId> column; an unknown or
				// malformed parent field is rejected like an unknown field.
				$ormName = self::resolveKnownParentColumn($camelName, $parentTypes, 'sort');
			}
			else
			{
				throw new UnknownSortFieldException($camelName);
			}

			$result[$ormName] = strcasecmp((string)$direction, 'DESC') === 0 ? 'DESC' : 'ASC';
		}

		return $result !== [] ? $result : null;
	}

	/**
	 * Rewrites camelCase keys in a Bitrix `setFilter`-style array to ORM column names,
	 * preserving operator prefixes (`=`, `>=`, `@`, `!@`, `%`, …).
	 *
	 * @param \Closure(): string[] $hiddenCustomFields Called only when a custom field is met.
	 * @param array<int, EntityType> $parentTypes
	 */
	private static function translateArrayFilterKeys(
		array $filter,
		FieldRegistry $registry,
		EntityType $entityType,
		\Closure $hiddenCustomFields,
		array $parentTypes,
	): array
	{
		$sqlWhere = new \CSQLWhere();
		$result = [];

		foreach ($filter as $key => $value)
		{
			if (is_int($key))
			{
				if (is_array($value))
				{
					$result[] = self::translateArrayFilterKeys($value, $registry, $entityType, $hiddenCustomFields, $parentTypes);
				}

				continue;
			}

			if (strtoupper((string)$key) === 'LOGIC')
			{
				$result[$key] = $value;

				continue;
			}

			$parsed = $sqlWhere->MakeOperation((string)$key);
			$camelName = (string)($parsed['FIELD'] ?? '');
			if ($camelName === '')
			{
				continue;
			}

			// Reconstruct: everything before the field-name part is the operator prefix.
			$prefix = substr((string)$key, 0, strlen((string)$key) - strlen($camelName));

			$descriptor = $registry->getField($camelName);
			if ($descriptor !== null)
			{
				$result[$prefix . $descriptor->ormName] = $value;

				continue;
			}

			// A known, visible UF filters by its raw column name (ORM resolves storage / multi alias);
			// a UF hidden from the access user is rejected like an unknown field.
			if (
				str_starts_with($camelName, 'UF_')
				&& CustomFieldRegistry::getInstance()->isCustomField($entityType, $camelName)
				&& !in_array($camelName, $hiddenCustomFields(), true)
			)
			{
				$result[$prefix . $camelName] = $value;

				continue;
			}

			// Filter by parent: the value is the parent ItemId - its type picks the PARENT_ID_<typeId>
			// column, its id the value. The 'parentId' key carries no type; the ItemId supplies it.
			if ($camelName === self::PARENT_FILTER_KEY && $value instanceof ItemId)
			{
				$parentColumn = self::resolveParentColumnFromItemId($value, $parentTypes);
				$result[$prefix . $parentColumn] = $value->getId();

				continue;
			}

			// Raw PARENT_ID_<typeId> key (e.g. from power users / mirrored legacy filters). Normalized
			// to its canonical column so a malformed suffix cannot leak to the ORM.
			if (ParentFieldManager::isParentFieldName($camelName))
			{
				$parentColumn = self::resolveKnownParentColumn($camelName, $parentTypes, 'filter');
				$result[$prefix . $parentColumn] = $value instanceof ItemId ? $value->getId() : $value;

				continue;
			}

			// The contract key is known, but its value is not a single ItemId (e.g. an ItemId array):
			// the parent filter selects one parent, so report the value shape instead of "unknown field".
			if ($camelName === self::PARENT_FILTER_KEY)
			{
				throw new ArgumentException(
					"Filter '" . self::PARENT_FILTER_KEY . "' accepts a single ItemId value.",
					'filter',
				);
			}

			throw new UnknownFilterFieldException($camelName);
		}

		return $result;
	}

	/**
	 * Replaces camelCase column names with ORM names everywhere inside a {@see ConditionTree}.
	 *
	 * @param \Closure(): string[] $hiddenCustomFields Called only when a custom field is met.
	 * @param array<int, EntityType> $parentTypes
	 */
	private static function translateTreeColumns(
		ConditionTree $tree,
		FieldRegistry $registry,
		EntityType $entityType,
		\Closure $hiddenCustomFields,
		array $parentTypes,
	): void
	{
		foreach ($tree->getConditions() as $node)
		{
			if ($node instanceof ConditionTree)
			{
				self::translateTreeColumns($node, $registry, $entityType, $hiddenCustomFields, $parentTypes);

				continue;
			}

			if (!$node instanceof Condition)
			{
				continue;
			}

			$column = $node->getColumn();
			if (!is_string($column))
			{
				continue;
			}

			$descriptor = $registry->getField($column);
			if ($descriptor !== null)
			{
				$node->setColumn($descriptor->ormName);

				continue;
			}

			// A known, visible UF is already addressed by its raw ORM column name - leave it untouched;
			// a UF hidden from the access user is rejected like an unknown field.
			if (
				str_starts_with($column, 'UF_')
				&& CustomFieldRegistry::getInstance()->isCustomField($entityType, $column)
				&& !in_array($column, $hiddenCustomFields(), true)
			)
			{
				continue;
			}

			// Filter by parent via the contract key: the value is the parent ItemId - its type picks
			// the PARENT_ID_<typeId> column, its id the compared value. Mirrors the array-filter path.
			$value = $node->getValue();
			if ($column === self::PARENT_FILTER_KEY && $value instanceof ItemId)
			{
				$node->setColumn(self::resolveParentColumnFromItemId($value, $parentTypes));
				$node->setValue($value->getId());

				continue;
			}

			// A raw PARENT_ID_<typeId> column - validate and normalize to its canonical form so a
			// malformed suffix cannot leak to the ORM; an unknown parent type is rejected as unknown.
			if (ParentFieldManager::isParentFieldName($column))
			{
				$node->setColumn(self::resolveKnownParentColumn($column, $parentTypes, 'filter'));

				continue;
			}

			// The contract key is known, but its value is not a single ItemId (e.g. an ItemId array):
			// the parent filter selects one parent, so report the value shape instead of "unknown field".
			if ($column === self::PARENT_FILTER_KEY)
			{
				throw new ArgumentException(
					"Filter '" . self::PARENT_FILTER_KEY . "' accepts a single ItemId value.",
					'filter',
				);
			}

			throw new UnknownFilterFieldException($column);
		}
	}

	/**
	 * Resolves the `PARENT_ID_<typeId>` ORM column for a parent {@see ItemId} filter value, using
	 * the ItemId's own entity type. Rejects a type this entity has no parent relation to.
	 *
	 * @param array<int, EntityType> $parentTypes
	 * @throws ArgumentException When the ItemId's type is not a known parent type.
	 */
	private static function resolveParentColumnFromItemId(ItemId $itemId, array $parentTypes): string
	{
		$parentTypeId = $itemId->getEntityType()->getId();
		if (!isset($parentTypes[$parentTypeId]))
		{
			throw new ArgumentException('Unknown parent entity type: ' . $parentTypeId, 'filter');
		}

		return ParentFieldManager::getParentFieldName($parentTypeId);
	}

	/**
	 * Validates a raw `PARENT_ID_<typeId>` field and returns its canonical ORM column name. Rejects
	 * a type this entity has no parent relation to, and rejects a malformed suffix whose `(int)` cast
	 * happens to hit a valid type (e.g. `PARENT_ID_2FOO`, `PARENT_ID_02`): the input must equal the
	 * canonical form, so a mangled name yields a contractual {@see ArgumentException} instead of
	 * leaking to the ORM as an unregistered column.
	 *
	 * @param array<int, EntityType> $parentTypes
	 * @throws ArgumentException When the parent type is unknown or the field name is not canonical.
	 */
	private static function resolveKnownParentColumn(string $parentFieldName, array $parentTypes, string $param): string
	{
		$parentTypeId = ParentFieldManager::getEntityTypeIdFromFieldName($parentFieldName);
		$canonical = ParentFieldManager::getParentFieldName($parentTypeId);
		if (!isset($parentTypes[$parentTypeId]) || $parentFieldName !== $canonical)
		{
			throw new ArgumentException('Unknown parent field: ' . $parentFieldName, $param);
		}

		return $canonical;
	}

	/**
	 * True when the current user can read the item under the active read-access mode.
	 */
	private function canReadItem(Item $item): bool
	{
		$id = $item->getId();
		if ($id === null)
		{
			return true;
		}

		$categoryId = $item instanceof HasCategoriesInterface ? $item->getCategoryId() : null;
		$identifier = new ItemIdentifier($this->getEntityType()->getId(), (int)$id, $categoryId);

		return Container::getInstance()
			->getUserPermissions($this->accessUserId)
			->item()
			->canReadItemIdentifier($identifier)
		;
	}

	/**
	 * Ensures categoryId is among the ORM fields so the per-item permission check resolves the
	 * real category (categorized entities only; no-op for wildcard select).
	 *
	 * @param string[] $ormFields
	 * @return string[]
	 */
	private function ensureCategoryFieldForAccessCheck(array $ormFields): array
	{
		if (in_array('*', $ormFields, true))
		{
			return $ormFields;
		}

		$settings = EntityTypeSettings::of($this->getEntityType());
		if (
			$settings->isCategoriesSupported()
			&& !in_array(LegacyItem::FIELD_NAME_CATEGORY_ID, $ormFields, true)
		)
		{
			$ormFields[] = LegacyItem::FIELD_NAME_CATEGORY_ID;
		}

		return array_values($ormFields);
	}

	/**
	 * Splits hydrated items by the active read-access mode:
	 * - no access mode: every item is kept and eligible for relation loading;
	 * - {@see AccessMode::Filter}: items the user can't read are dropped;
	 * - {@see AccessMode::Restricted}: unreadable items are replaced by data-less stubs (id +
	 *   restricted flag only) and excluded from relation loading.
	 *
	 * @param Item[] $items
	 * @return array{0: Item[], 1: Item[]} kept items (original order), loadable readable items
	 */
	private function partitionByAccess(array $items): array
	{
		if ($this->accessMode === null)
		{
			return [$items, $items];
		}

		$this->preloadPermissions($items);

		$kept = [];
		$loadable = [];
		foreach ($items as $item)
		{
			if ($this->canReadItem($item))
			{
				$kept[] = $item;
				$loadable[] = $item;

				continue;
			}

			if ($this->accessMode === AccessMode::Restricted)
			{
				$kept[] = $this->toRestrictedStub($item);
			}
			// AccessMode::Filter: drop the unreadable item entirely.
		}

		return [$kept, $loadable];
	}

	/**
	 * Builds a data-less stub for a restricted item: only the id survives, plus the restricted
	 * flag. No scalars, no loaded relations — so no protected data leaks through the stub.
	 */
	private function toRestrictedStub(Item $item): Item
	{
		$stub = ItemFactory::create($this->getEntityType());

		$id = $item->getId();
		if ($id !== null)
		{
			$stub->setId($id);
		}

		$stub->markAsRestricted();

		return $stub;
	}

	/**
	 * Batch-warms permission attributes for the whole id set so the per-item read checks that
	 * follow don't degrade into N+1 attribute lookups.
	 *
	 * @param Item[] $items
	 */
	private function preloadPermissions(array $items): void
	{
		$ids = [];
		foreach ($items as $item)
		{
			$id = $item->getId();
			if ($id !== null)
			{
				$ids[] = $id;
			}
		}

		if (empty($ids))
		{
			return;
		}

		Container::getInstance()
			->getUserPermissions($this->accessUserId)
			->item()
			->preloadPermissionAttributes($this->getEntityType()->getId(), $ids)
		;
	}

	/**
	 * Builds DB-level read-permission restrictions for `getList()` / `getCount()`. Mirrors
	 * {@see \Bitrix\Crm\Service\Factory::getItemsFilteredByPermissions()} but stays inside the
	 * V2 stack: invokes {@see \Bitrix\Crm\Service\UserPermissions::itemsList()} machinery to
	 * either inject a permission INNER JOIN (`runtime`) or amend the filter.
	 *
	 * The machinery emits its restrictions in Bitrix `setFilter` shape. For an array (or absent)
	 * caller filter that shape is returned verbatim. For a caller-built {@see ConditionTree} the
	 * restrictions are folded back into the tree via {@see mergeRestrictionsIntoTree()} so they
	 * are never silently dropped; if a restriction can't be safely folded it throws instead of
	 * running an unrestricted query (fail-closed).
	 *
	 * @param string[] $ormFields
	 * @return array{0: string[], 1: array|ConditionTree|null, 2: ?array, 3: bool} new select,
	 *         new filter, new runtime, `denied` flag (true ⇒ no rows visible — short-circuit).
	 * @throws ArgumentException When a ConditionTree filter can't absorb a permission restriction.
	 */
	private function applyPermissionFilter(array $ormFields, array|ConditionTree|null $filter): array
	{
		$permissions = Container::getInstance()->getUserPermissions($this->accessUserId);
		if ($permissions->getUserId() === 0)
		{
			return [$ormFields, $filter, null, true];
		}

		$filterArray = is_array($filter) ? $filter : [];
		$permissionEntityTypes = $this->collectPermissionEntityTypes($filterArray, $permissions);

		// Permission machinery wants categoryId available downstream; ensure it's selected for
		// categorized entities (no-op for wildcard).
		$ormFields = $this->ensureCategoryFieldForAccessCheck($ormFields);

		$prepared = $permissions->itemsList()->applyAvailableItemsGetListParameters(
			[
				'select' => $ormFields,
				'filter' => $filterArray,
			],
			$permissionEntityTypes,
		);

		$newRuntime = $prepared['runtime'] ?? null;
		$newSelect = $prepared['select'] ?? $ormFields;
		$preparedFilter = $prepared['filter'] ?? $filterArray;

		// Array or absent caller filter: the prepared array already carries every restriction.
		if (!($filter instanceof ConditionTree))
		{
			return [$newSelect, $preparedFilter, $newRuntime, false];
		}

		// ConditionTree caller filter: fold the array-shaped restrictions back into the tree.
		$newFilter = self::mergeRestrictionsIntoTree($filter, $preparedFilter);

		return [$newSelect, $newFilter, $newRuntime, false];
	}

	/**
	 * ANDs the array-shaped permission restrictions into a copy of the caller's {@see ConditionTree}.
	 * A fresh AND root wraps the caller tree as a subgroup (so the caller's own inner logic — e.g.
	 * an OR root — can't widen the restriction) and the restrictions are added alongside it.
	 *
	 * @throws ArgumentException When a restriction can't be safely converted (fail-closed).
	 */
	private static function mergeRestrictionsIntoTree(
		ConditionTree $userTree,
		array $restrictionFilter,
	): ConditionTree
	{
		$root = new ConditionTree();
		if ($userTree->hasConditions())
		{
			$root->where($userTree);
		}

		self::applyArrayRestrictions($root, $restrictionFilter);

		return $root;
	}

	/**
	 * Translates a Bitrix `setFilter`-shaped permission restriction onto $target. Only the shapes
	 * the permission machinery emits are supported — `@COLUMN` IN (values / subquery / SqlExpression),
	 * equality and nested AND-groups; anything else throws so a restriction is never silently lost.
	 *
	 * @throws ArgumentException When a restriction entry can't be safely folded into the tree.
	 */
	private static function applyArrayRestrictions(ConditionTree $target, array $filter): void
	{
		foreach ($filter as $key => $value)
		{
			if (is_int($key))
			{
				if (!is_array($value))
				{
					throw new ArgumentException(
						'Cannot fold permission restriction into ConditionTree: non-array group.',
					);
				}

				$subFilter = new ConditionTree();
				self::applyArrayRestrictions($subFilter, $value);
				if ($subFilter->hasConditions())
				{
					$target->where($subFilter);
				}

				continue;
			}

			if (strtoupper((string)$key) === 'LOGIC')
			{
				$target->logic((string)$value);

				continue;
			}

			$parsed = (new \CSQLWhere())->MakeOperation((string)$key);
			$column = (string)($parsed['FIELD'] ?? '');
			$operation = (string)($parsed['OPERATION'] ?? '');
			if ($column === '')
			{
				throw new ArgumentException(
					'Cannot fold permission restriction into ConditionTree: unparseable key "' . $key . '".',
				);
			}

			switch ($operation)
			{
				case 'IN': // "@" prefix — IN (values) / IN (subquery)
					if (is_array($value))
					{
						if ($value === [])
						{
							throw new ArgumentException(
								'Cannot fold empty IN permission restriction into ConditionTree.',
							);
						}
						$target->whereIn($column, $value);
					}
					elseif ($value instanceof SqlExpression || $value instanceof Query)
					{
						$target->whereIn($column, $value);
					}
					else
					{
						throw new ArgumentException(
							'Cannot fold IN permission restriction with a scalar value into ConditionTree.',
						);
					}
					break;

				case 'I': // "=" prefix or no prefix — equality
				case 'E':
				case 'SE':
					$target->where($column, '=', $value);
					break;

				default:
					throw new ArgumentException(
						'Cannot safely fold permission restriction operator "' . $operation
						. '" into ConditionTree.',
					);
			}
		}
	}

	/**
	 * Builds the list of CRM permission entity types relevant for the current read. Mirrors
	 * {@see \Bitrix\Crm\Service\Factory::collectEntityTypesForPermissions()} — for categorized
	 * entities it inspects the filter and the user's per-category access; for the rest it
	 * returns a single universal permission type.
	 *
	 * @return string[]
	 */
	private function collectPermissionEntityTypes(array &$filterArray, UserPermissions $permissions): array
	{
		$entityTypeId = $this->getEntityType()->getId();
		$helper = new PermissionEntityTypeHelper($entityTypeId);

		$settings = EntityTypeSettings::of($this->getEntityType());
		if (!$settings->isCategoriesSupported())
		{
			return [$helper->getPermissionEntityTypeForCategory(0)];
		}

		$categoryCondition = self::findCategoryIdCondition($filterArray);
		if (
			$categoryCondition !== null
			&& in_array($categoryCondition['operation'], ['=', 'IN'], true)
		)
		{
			$entityTypes = [];
			foreach ((array)$categoryCondition['value'] as $categoryId)
			{
				$categoryId = (int)$categoryId;
				if ($categoryId >= 0)
				{
					$entityTypes[] = $helper->getPermissionEntityTypeForCategory($categoryId);
				}
			}

			return $entityTypes;
		}

		// No explicit category condition — enumerate all the entity's categories.
		$entityTypes = [];
		$availableCategoryIds = [];
		$shouldStrictByCategories = false;

		$legacyFactory = Container::getInstance()->getFactory($entityTypeId);
		$categories = $legacyFactory !== null ? $legacyFactory->getCategories() : [];

		foreach ($categories as $category)
		{
			$categoryId = $category->getId();
			$entityTypes[] = $helper->getPermissionEntityTypeForCategory($categoryId);

			if ($permissions->entityType()->canReadItemsInCategory($entityTypeId, $categoryId))
			{
				$availableCategoryIds[] = $categoryId;
			}
			else
			{
				$shouldStrictByCategories = true;
			}
		}

		if ($shouldStrictByCategories && !empty($availableCategoryIds))
		{
			if (mb_strtoupper((string)($filterArray['LOGIC'] ?? '')) === 'OR')
			{
				$filterArray = [
					0 => $filterArray,
					'@' . LegacyItem::FIELD_NAME_CATEGORY_ID => $availableCategoryIds,
				];
			}
			else
			{
				$filterArray['@' . LegacyItem::FIELD_NAME_CATEGORY_ID] = $availableCategoryIds;
			}
		}

		return $entityTypes;
	}

	/**
	 * Finds the first array-filter entry whose column equals `$columnName`. Returns the parsed
	 * `['operation' => '=' | 'IN' | '>=' | …, 'value' => mixed]` or `null` if absent.
	 */
	private static function findCategoryIdCondition(array $filter): ?array
	{
		$sqlWhere = new \CSQLWhere();
		foreach ($filter as $key => $value)
		{
			if (!is_string($key))
			{
				continue;
			}
			$parsed = $sqlWhere->MakeOperation($key);
			if (($parsed['FIELD'] ?? '') !== LegacyItem::FIELD_NAME_CATEGORY_ID)
			{
				continue;
			}

			$operation = match ($parsed['OPERATION'] ?? '')
			{
				'I', 'SE', 'E' => '=',
				'IN' => 'IN',
				'GE' => '>=',
				'LE' => '<=',
				'G' => '>',
				'L' => '<',
				'NI', 'SN', 'N' => '!=',
				'NIN' => 'NOT IN',
				default => null,
			};
			if ($operation === null)
			{
				continue;
			}

			return ['operation' => $operation, 'value' => $value];
		}

		return null;
	}

	private static function isRelationFieldType(FieldType $type): bool
	{
		return in_array(
			$type,
			[
				FieldType::MultifieldCollection,
				FieldType::ProductRowCollection,
				FieldType::ContactBindingCollection,
				FieldType::CompanyBindingCollection,
				FieldType::ObserverCollection,
				FieldType::Utm,
			],
			true,
		);
	}

	/**
	 * @param Item[] $items
	 */
	private function applyLoaders(array $items, ItemSelect $select): void
	{
		if ($select->shouldLoadAnyMultifield())
		{
			(new MultifieldLoader())->load($items, $select);
		}

		if ($select->shouldLoadProductRows())
		{
			(new ProductRowLoader())->load($items, $select);
		}

		if ($select->shouldLoadContactBindings())
		{
			(new ContactBindingLoader())->load($items, $select);
		}

		if ($select->shouldLoadCompanyBindings())
		{
			(new CompanyBindingLoader())->load($items, $select);
		}

		if ($select->shouldLoadObservers())
		{
			(new ObserverLoader())->load($items, $select);
		}

		if ($select->shouldLoadParents())
		{
			(new ParentLoader())->load($items, $select);
		}
	}

	/** @param Item[] $items */
	private function applyTaskLoaders(array $items, ItemSelect $select): void
	{
		if (empty($items))
		{
			return;
		}

		if ($select->shouldLoadEmployee())
		{
			(new EmployeeLoader())->load($items, $select);
		}

		if ($select->shouldLoadUtm())
		{
			(new UtmLoader())->load($items, $select);
		}

		if ($select->shouldLoadTrackingSource())
		{
			(new TrackingSourceLoader())->load($items, $select);
		}

		if ($select->shouldLoadRelatedCrmObjects())
		{
			(new ItemLoader($this->accessUserId))->load($items, $select);
		}

		if ($select->shouldLoadDictionaries())
		{
			(new DictionaryLoader($this->accessUserId))->load($items, $select);
		}

		if ($select->shouldLoadLastCommunication())
		{
			(new LastCommunicationLoader())->load($items, $select);
		}

		if ($select->shouldLoadCustomFieldData())
		{
			(new CustomFieldDataLoader($this->accessUserId))->load($items, $select);
		}
	}
}
