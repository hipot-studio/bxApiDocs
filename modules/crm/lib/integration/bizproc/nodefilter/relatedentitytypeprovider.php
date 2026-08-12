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
	 * TODO (follow-up, Q-1): replace this static whitelist with a dynamic lookup of
	 * processable types exposed by the complex node actions (ComplexActivityService).
	 *
	 * @var int[]
	 */
	private const PROCESSABLE_RELATED_ENTITY_TYPE_IDS = [
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
	 * Returns the related (parent) entity type ids a complex node of the given context may target in
	 * its filter. Only parents that resolve to a CRM bizproc document type are kept.
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

		$candidateTypeIds = \CCrmOwnerType::isPossibleDynamicTypeId($contextEntityTypeId)
			? self::DYNAMIC_RELATED_ENTITY_TYPE_IDS
			: self::getParentEntityTypeIds(
				$contextEntityTypeId,
				$relationManager ?? Container::getInstance()->getRelationManager(),
			)
		;

		$processableIds = array_flip(self::PROCESSABLE_RELATED_ENTITY_TYPE_IDS);

		$relatedTypeIds = [];
		foreach ($candidateTypeIds as $entityTypeId)
		{
			if (
				$entityTypeId > 0
				&& $entityTypeId !== $contextEntityTypeId
				&& isset($processableIds[$entityTypeId])
				&& is_array(\CCrmBizProcHelper::ResolveDocumentType($entityTypeId))
			)
			{
				$relatedTypeIds[$entityTypeId] = $entityTypeId;
			}
		}

		return array_values($relatedTypeIds);
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
}
