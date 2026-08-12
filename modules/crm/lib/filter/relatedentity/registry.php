<?php

namespace Bitrix\Crm\Filter\RelatedEntity;

use Bitrix\Crm\Relation\StorageStrategy;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Data\Cache;
use CCrmOwnerType;

/**
 * Knows which ConditionBuilder must be used for a given (source, target) entity type pair,
 * and serves as a single point that limits the MVP whitelist of supported relation types.
 *
 * The class is intentionally free of any direct DB or service-locator calls in the dispatch
 * methods - it only maps pairs to builder instances. Resolving allowed targets for a source
 * entity goes through the same dispatch logic, so a pair is "supported" exactly when there
 * is a builder for it.
 */
final class Registry implements ConditionBuilderResolver
{
	/**
	 * Short TTL: there is no event-based invalidation on RelationManager::bindTypes /
	 * dynamic type create / delete, so an admin who binds a new pair would otherwise wait
	 * up to this many seconds before it appears in the filter dropdown. 60s is the upper
	 * bound on that admin-facing UX delay; the hasAnyRecords probe is one indexed
	 * EXISTS LIMIT 1 per pair (~8 calls on a typical portal) so the per-request cost of
	 * a cache miss is small.
	 */
	private const NON_EMPTY_CACHE_TTL = 60;
	private const NON_EMPTY_CACHE_DIR = '/crm/related_entity_filter';

	private ?EntityContactConditionBuilder $entityContactBuilder = null;

	/**
	 * @var array<int, list<\Bitrix\Crm\Relation>> RelationManager::getRelations() returns a Collection
	 * with a stateful iterator - re-entering the same collection from inside foreach() resets the
	 * outer loop. We materialize it into a plain array on first access and reuse it for all
	 * subsequent dispatch calls within a single Registry instance.
	 */
	private array $relationsByType = [];

	private ?\ReflectionMethod $storageStrategyAccessor = null;

	/** @var array<class-string, \ReflectionProperty> */
	private array $parentIdFieldAccessors = [];

	/** @var array<string, bool> */
	private array $hasAnyRecordsCache = [];

	/**
	 * Legacy invoice (entityTypeId 5) is out of MVP scope for the related-entities filter:
	 * it is being phased out in favour of SmartInvoice and we do not invest in supporting
	 * the FK path on it. Technical side effects of including it would also be unpleasant
	 * (no index on UF_DEAL_ID, sale-order properties stored separately), but the primary
	 * reason is product scope, not technical limitation.
	 */
	private const FK_EXCLUDED_CHILD_TYPES = [
		CCrmOwnerType::Invoice,
	];

	/**
	 * Returns a list of target entity type IDs that have a supported binding-based relation
	 * with the given source entity type. Static binding tables and the polymorphic
	 * b_crm_entity_contact / b_crm_entity_relation are taken into account; relations stored
	 * via a column in the main table (Compatible / Factory storage) are out of scope.
	 *
	 * @return int[] Target entityTypeId values (deduplicated).
	 */
	public function getSupportedTargetTypeIds(int $sourceTypeId): array
	{
		$result = [];
		foreach ($this->getRelationsFor($sourceTypeId) as $relation)
		{
			$parent = $relation->getParentEntityTypeId();
			$child = $relation->getChildEntityTypeId();
			$target = ($parent === $sourceTypeId) ? $child : $parent;

			if ($this->getConditionBuilder($sourceTypeId, $target) !== null)
			{
				$result[$target] = $target;
			}
		}

		return array_values($result);
	}

	/**
	 * Same as getSupportedTargetTypeIds(), but additionally drops pairs whose binding storage is empty.
	 * Used to populate the filter UI so that the user does not see options that would always
	 * return either an empty list ("has") or every entity ("no").
	 *
	 * Result is cached for {@see self::NON_EMPTY_CACHE_TTL} seconds - the binding tables change
	 * relatively rarely and a short delay before a new option appears is acceptable for filter UX.
	 *
	 * @return int[]
	 */
	public function getNonEmptyTargetTypeIds(int $sourceTypeId): array
	{
		$cache = Cache::createInstance();
		$cacheKey = "non_empty_targets_{$sourceTypeId}";
		if ($cache->initCache(self::NON_EMPTY_CACHE_TTL, $cacheKey, self::NON_EMPTY_CACHE_DIR))
		{
			return $cache->getVars();
		}

		$nonEmpty = [];
		foreach ($this->getSupportedTargetTypeIds($sourceTypeId) as $targetTypeId)
		{
			$builder = $this->getConditionBuilder($sourceTypeId, $targetTypeId);
			if ($builder !== null && $this->hasAnyRecordsCached($builder, $sourceTypeId, $targetTypeId))
			{
				$nonEmpty[] = $targetTypeId;
			}
		}

		$cache->startDataCache();
		$cache->endDataCache($nonEmpty);

		return $nonEmpty;
	}

	/**
	 * Resolves a builder for the (source, target) pair.
	 * Returns null when the pair is not supported in the current iteration.
	 */
	public function getConditionBuilder(int $sourceTypeId, int $targetTypeId): ?ConditionBuilder
	{
		$staticBinding = $this->resolveStaticBindingBuilder($sourceTypeId, $targetTypeId);
		if ($staticBinding !== null)
		{
			return $staticBinding;
		}

		if ($this->isContactPolymorphicPair($sourceTypeId, $targetTypeId))
		{
			return $this->entityContactBuilder ??= new EntityContactConditionBuilder();
		}

		// Falls back to the polymorphic b_crm_entity_relation table for any pair that is registered
		// in RelationManager but not covered by the two cases above. Includes static-to-dynamic
		// pairs (Deal <-> SmartInvoice, Quote <-> smart-process, etc.) and custom relations
		// registered via RelationManager::bindTypes().
		$sourceIsParent = $this->resolveEntityRelationDirection($sourceTypeId, $targetTypeId);
		if ($sourceIsParent !== null)
		{
			return new EntityRelationConditionBuilder($sourceIsParent);
		}

		// Compatible / Factory storage: relations stored as a foreign key column in the child
		// entity's main table (Deal.LEAD_ID, Quote.DEAL_ID, smart-item.CONTACT_ID, etc.).
		$fkBuilder = $this->resolveFkBuilder($sourceTypeId, $targetTypeId);
		if ($fkBuilder !== null)
		{
			return $fkBuilder;
		}

		return null;
	}

	private function resolveStaticBindingBuilder(int $a, int $b): ?EntityBindingConditionBuilder
	{
		$configs = [
			$this->pairKey(CCrmOwnerType::Lead, CCrmOwnerType::Contact) => [
				'table' => 'b_crm_lead_contact',
				'fields' => [
					CCrmOwnerType::Lead => 'LEAD_ID',
					CCrmOwnerType::Contact => 'CONTACT_ID',
				],
			],
			$this->pairKey(CCrmOwnerType::Deal, CCrmOwnerType::Contact) => [
				'table' => 'b_crm_deal_contact',
				'fields' => [
					CCrmOwnerType::Deal => 'DEAL_ID',
					CCrmOwnerType::Contact => 'CONTACT_ID',
				],
			],
			// Quote keeps its contact bindings in the classic b_crm_quote_contact table (ORM field
			// Quote.CONTACT_BINDINGS), exactly like Deal/Lead - NOT in the polymorphic
			// b_crm_entity_contact. See isPolymorphicContactPartner() for why Quote is excluded there.
			$this->pairKey(CCrmOwnerType::Quote, CCrmOwnerType::Contact) => [
				'table' => 'b_crm_quote_contact',
				'fields' => [
					CCrmOwnerType::Quote => 'QUOTE_ID',
					CCrmOwnerType::Contact => 'CONTACT_ID',
				],
			],
			$this->pairKey(CCrmOwnerType::Contact, CCrmOwnerType::Company) => [
				'table' => 'b_crm_contact_company',
				'fields' => [
					CCrmOwnerType::Contact => 'CONTACT_ID',
					CCrmOwnerType::Company => 'COMPANY_ID',
				],
			],
		];

		$config = $configs[$this->pairKey($a, $b)] ?? null;
		if ($config === null)
		{
			return null;
		}

		return new EntityBindingConditionBuilder($config['table'], $config['fields']);
	}

	private function pairKey(int $a, int $b): string
	{
		return min($a, $b) . ':' . max($a, $b);
	}

	private function isContactPolymorphicPair(int $sourceTypeId, int $targetTypeId): bool
	{
		if ($sourceTypeId === CCrmOwnerType::Contact)
		{
			return $this->isPolymorphicContactPartner($targetTypeId);
		}

		if ($targetTypeId === CCrmOwnerType::Contact)
		{
			return $this->isPolymorphicContactPartner($sourceTypeId);
		}

		return false;
	}

	/**
	 * Quote is deliberately NOT here: despite sharing the ContactToFactory storage strategy with
	 * SmartInvoice and dynamic types, Quote stores its contact bindings in the classic
	 * b_crm_quote_contact table, not in the polymorphic b_crm_entity_contact. It is routed through
	 * resolveStaticBindingBuilder() instead (bug 689157 / Mantis 250913).
	 */
	private function isPolymorphicContactPartner(int $typeId): bool
	{
		if ($typeId === CCrmOwnerType::SmartInvoice)
		{
			return true;
		}

		return CCrmOwnerType::isPossibleDynamicTypeId($typeId);
	}

	/**
	 * Returns true when source maps to SRC_ENTITY_*, false when source maps to DST_ENTITY_*,
	 * null when no EntityRelationTable-based relation between the two types is registered in
	 * RelationManager. We restrict to EntityRelationTable here so that Compatible / Factory pairs
	 * (which live outside b_crm_entity_relation) fall through to the FK builder instead.
	 */
	private function resolveEntityRelationDirection(int $sourceTypeId, int $targetTypeId): ?bool
	{
		foreach ($this->getRelationsFor($sourceTypeId) as $relation)
		{
			$parent = $relation->getParentEntityTypeId();
			$child = $relation->getChildEntityTypeId();

			$matchesForward = ($parent === $sourceTypeId && $child === $targetTypeId);
			$matchesReverse = ($parent === $targetTypeId && $child === $sourceTypeId);

			if (!$matchesForward && !$matchesReverse)
			{
				continue;
			}

			$strategy = $this->getStorageStrategyAccessor()->invoke($relation);
			if (!($strategy instanceof StorageStrategy\EntityRelationTable))
			{
				continue;
			}

			return $matchesForward;
		}

		return null;
	}

	/**
	 * @return list<\Bitrix\Crm\Relation>
	 */
	private function getRelationsFor(int $sourceTypeId): array
	{
		if (!isset($this->relationsByType[$sourceTypeId]))
		{
			$collection = Container::getInstance()->getRelationManager()->getRelations($sourceTypeId);
			$this->relationsByType[$sourceTypeId] = iterator_to_array($collection, false);
		}

		return $this->relationsByType[$sourceTypeId];
	}

	/**
	 * Builds a ConditionBuilder for a (source, target) pair backed by Compatible / Factory
	 * storage. Several relation directions can co-exist for one pair (e.g. Deal-Quote has both
	 * Quote.DEAL_ID and Deal.QUOTE_ID legacy FKs registered in RelationManager). On portals with
	 * historically mixed data both columns may carry valid bindings, so we have to probe all
	 * candidates together via OR / AND rather than pick a single one. For a single-candidate
	 * pair the bare FkConditionBuilder is returned; for two or more we wrap them into a
	 * CompositeFkConditionBuilder that combines their predicates.
	 */
	private function resolveFkBuilder(int $sourceTypeId, int $targetTypeId): ?ConditionBuilder
	{
		$candidates = $this->collectFkBuilderCandidates($sourceTypeId, $targetTypeId);
		if (empty($candidates))
		{
			return null;
		}

		if (count($candidates) === 1)
		{
			return $candidates[0];
		}

		return new CompositeFkConditionBuilder($candidates);
	}

	/**
	 * @return list<FkConditionBuilder>
	 */
	private function collectFkBuilderCandidates(int $sourceTypeId, int $targetTypeId): array
	{
		$candidates = [];

		foreach ($this->getRelationsFor($sourceTypeId) as $relation)
		{
			$parent = $relation->getParentEntityTypeId();
			$child = $relation->getChildEntityTypeId();

			$sourceIsParent = ($parent === $sourceTypeId && $child === $targetTypeId);
			$sourceIsChild = ($child === $sourceTypeId && $parent === $targetTypeId);

			if (!$sourceIsParent && !$sourceIsChild)
			{
				continue;
			}

			if (in_array($child, self::FK_EXCLUDED_CHILD_TYPES, true))
			{
				continue;
			}

			$strategy = $this->getStorageStrategyAccessor()->invoke($relation);
			if (!($strategy instanceof StorageStrategy\Compatible) && !($strategy instanceof StorageStrategy\Factory))
			{
				continue;
			}

			$parentIdField = $this->extractParentIdFieldName($strategy);
			if ($parentIdField === null)
			{
				continue;
			}

			$childFactory = Container::getInstance()->getFactory($child);
			if ($childFactory === null)
			{
				continue;
			}

			$dataClass = $childFactory->getDataClass();
			if (!is_subclass_of($dataClass, \Bitrix\Main\ORM\Data\DataManager::class))
			{
				continue;
			}

			$candidate = new FkConditionBuilder(
				$dataClass::getTableName(),
				$parentIdField,
				$sourceIsParent
			);

			// Drop misconfigured registrations whose FK column does not actually exist in the
			// target table - without this guard the composite would emit SQL referring to a
			// nonexistent column and crash the whole filter SQL. Legitimately empty (but
			// schema-present) columns are kept so that data added later is picked up correctly.
			if (!$candidate->isStorageAvailable())
			{
				continue;
			}

			$candidates[] = $candidate;
		}

		return $candidates;
	}

	/**
	 * Deduplicates hasAnyRecords() probes across multiple callers within a single Registry
	 * lifetime. The cache key combines the builder's storage fingerprint (see ConditionBuilder::getCacheKey())
	 * with the pair, so freshly constructed equivalent builders share the same slot.
	 */
	private function hasAnyRecordsCached(
		ConditionBuilder $builder,
		int $sourceTypeId,
		int $targetTypeId
	): bool
	{
		$key = $builder->getCacheKey() . '#' . $sourceTypeId . '|' . $targetTypeId;
		return $this->hasAnyRecordsCache[$key] ??= $builder->hasAnyRecords($sourceTypeId, $targetTypeId);
	}

	/**
	 * Lazily-built reflection accessor for Relation::getStorageStrategy(), which is protected.
	 * TODO: switch to a public getter once it's added to Bitrix\Crm\Relation; the reflection
	 * call is here only because the strategy is required to route a pair to the FK builder vs
	 * the polymorphic b_crm_entity_relation builder and there is no public API for it yet.
	 */
	private function getStorageStrategyAccessor(): \ReflectionMethod
	{
		if ($this->storageStrategyAccessor === null)
		{
			$this->storageStrategyAccessor = new \ReflectionMethod(
				\Bitrix\Crm\Relation::class,
				'getStorageStrategy'
			);
		}

		return $this->storageStrategyAccessor;
	}

	private function extractParentIdFieldName(StorageStrategy\Compatible|StorageStrategy\Factory $strategy): ?string
	{
		$class = get_class($strategy);
		if (!isset($this->parentIdFieldAccessors[$class]))
		{
			$prop = new \ReflectionProperty($class, 'parentIdFieldName');
			$this->parentIdFieldAccessors[$class] = $prop;
		}

		$value = $this->parentIdFieldAccessors[$class]->getValue($strategy);

		return is_string($value) && $value !== '' ? $value : null;
	}
}
