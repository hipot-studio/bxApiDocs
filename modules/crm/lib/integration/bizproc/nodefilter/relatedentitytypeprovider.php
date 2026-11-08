<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc\NodeFilter;

use Bitrix\Crm\Relation\RelationManager;
use Bitrix\Crm\Service\Container;

final class RelatedEntityTypeProvider
{
	/**
	 * @var int[]
	 */
	private const SUPPORTED_CONTEXT_ENTITY_TYPE_IDS = [
		\CCrmOwnerType::Deal,
		\CCrmOwnerType::Company,
		\CCrmOwnerType::Contact,
		\CCrmOwnerType::Lead,
		\CCrmOwnerType::Quote,
		\CCrmOwnerType::SmartInvoice,
	];

	/**
	 * Candidates joined to the parents from the relation graph in a smart process context: they go through the
	 * same filtering as the parents, so only the processable ones among them end up offered.
	 * The graph does not replace this list: it holds no contact/company pair for a smart process with the
	 * client fields turned off, where these two still have to be offered.
	 *
	 * @var int[]
	 */
	private const DYNAMIC_RELATED_ENTITY_TYPE_IDS = [
		\CCrmOwnerType::Lead,
		\CCrmOwnerType::Contact,
		\CCrmOwnerType::Company,
		\CCrmOwnerType::Deal,
		\CCrmOwnerType::Quote,
		\CCrmOwnerType::SmartInvoice,
	];

	/**
	 * Entity types whose documents the node-filter runtime can actually resolve.
	 *
	 * @var int[]
	 */
	private const PROCESSABLE_CLASSIC_RELATED_ENTITY_TYPE_IDS = [
		\CCrmOwnerType::Contact,
		\CCrmOwnerType::Company,
	];

	/**
	 * Resolves the context entity type id from a complex node document type
	 * (e.g. ['crm', 'CCrmDocumentDeal', 'DEAL'] or a dynamic 'DYNAMIC_123' moduleId).
	 * Returns CCrmOwnerType::Undefined (0) when the document type is not a CRM one.
	 */
	public static function resolveContextEntityTypeId(array $contextDocumentType): int
	{
		if (($contextDocumentType[0] ?? null) !== 'crm')
		{
			return \CCrmOwnerType::Undefined;
		}

		return \CCrmOwnerType::ResolveID((string)($contextDocumentType[2] ?? ''));
	}

	public static function isSupportedContext(int $contextEntityTypeId): bool
	{
		return in_array($contextEntityTypeId, self::SUPPORTED_CONTEXT_ENTITY_TYPE_IDS, true)
			|| \CCrmOwnerType::isPossibleDynamicTypeId($contextEntityTypeId);
	}

	/**
	 * Returns the related entity type ids a complex node of the given context may target in its filter:
	 * the parents and the children of the context type, joined for a smart process context with a fixed set
	 * of candidates.
	 * Only types that resolve to a CRM bizproc document type are kept.
	 *
	 * A smart process id is only range-checked here, so a type deleted while its relation stayed in the graph
	 * is still returned: checking that the type exists is up to the caller.
	 *
	 * The relation graph dependency is injectable so the resolution can be unit-tested in isolation;
	 * when omitted it defaults to the CRM relation manager from the service container.
	 *
	 * @return int[]
	 */
	public static function getRelatedEntityTypeIds(
		array $contextDocumentType,
		?RelationManager $relationManager = null
	): array
	{
		$contextEntityTypeId = self::resolveContextEntityTypeId($contextDocumentType);
		if ($contextEntityTypeId <= 0 || !self::isSupportedContext($contextEntityTypeId))
		{
			return [];
		}

		$relationManager ??= Container::getInstance()->getRelationManager();

		$candidateTypeIds = self::getParentEntityTypeIds($contextEntityTypeId, $relationManager);
		if (\CCrmOwnerType::isPossibleDynamicTypeId($contextEntityTypeId))
		{
			$candidateTypeIds = array_merge(self::DYNAMIC_RELATED_ENTITY_TYPE_IDS, $candidateTypeIds);
		}

		$candidateTypeIds = array_merge(
			$candidateTypeIds,
			self::getChildEntityTypeIds($contextEntityTypeId, $relationManager),
		);

		$relatedTypeIds = [];
		foreach ($candidateTypeIds as $entityTypeId)
		{
			if (
				$entityTypeId > 0
				&& $entityTypeId !== $contextEntityTypeId
				&& self::isProcessableRelatedType($entityTypeId)
				&& is_array(\CCrmBizProcHelper::ResolveDocumentType($entityTypeId))
			)
			{
				$relatedTypeIds[$entityTypeId] = $entityTypeId;
			}
		}

		return array_values($relatedTypeIds);
	}

	/**
	 * Smart processes are processable in addition to the whitelist above. The predicate is deliberately
	 * isPossibleDynamicTypeId(): system types built on the dynamic approach (invoice, documents) stay out.
	 *
	 * TODO (follow-up, Q-1): replace this static whitelist with a dynamic lookup of
	 * processable types exposed by the complex node actions (ComplexActivityService).
	 */
	private static function isProcessableRelatedType(int $entityTypeId): bool
	{
		return in_array($entityTypeId, self::PROCESSABLE_CLASSIC_RELATED_ENTITY_TYPE_IDS, true)
			|| \CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId);
	}

	/**
	 * Parent entity type ids of the given entity, taken from the CRM relation graph.
	 *
	 * @return int[]
	 */
	private static function getParentEntityTypeIds(
		int $contextEntityTypeId,
		RelationManager $relationManager
	): array
	{
		$parentTypeIds = [];
		foreach ($relationManager->getParentRelations($contextEntityTypeId) as $relation)
		{
			$parentTypeIds[] = $relation->getParentEntityTypeId();
		}

		return $parentTypeIds;
	}

	/**
	 * Child entity type ids of the given entity, taken from the CRM relation graph.
	 *
	 * @return int[]
	 */
	private static function getChildEntityTypeIds(
		int $contextEntityTypeId,
		RelationManager $relationManager
	): array
	{
		$childTypeIds = [];
		foreach ($relationManager->getChildRelations($contextEntityTypeId) as $relation)
		{
			$childTypeIds[] = $relation->getChildEntityTypeId();
		}

		return $childTypeIds;
	}
}
