<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc\NodeFilter;

use Bitrix\Crm\Relation\RelationManager;
use Bitrix\Crm\RelationIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\ParentFieldManager;

/**
 * Design-time resolver of relation fields for creating a CRM element linked to an ancestor node.
 *
 * Supportability of a (source -> target) pair is decided dynamically over the CRM relation graph
 * (RelationManager), not by a static whitelist. Two kinds of link fields are covered, chosen by the
 * nature of the relation, not by the target entity type:
 *  - custom/dynamic/SPA relation  -> PARENT_ID_<sourceTypeId> carried by the created element;
 *  - predefined Contact/Company   -> classic UF:crm field (CONTACT_ID / COMPANY_ID) of the created element.
 *
 * Direction invariant: only a field that the created (target) element holds as a reference to the
 * source is autofilled. If the link field lives on the source instead, the pair is not autofilled
 * (empty map, fail-open).
 */
final class RelationFieldResolver implements \Bitrix\Bizproc\Public\Activity\Interface\RelationFieldResolver
{
	/**
	 * Field of the source entity that feeds the target link field.
	 *
	 * The link field on the created element stores the id of the bound source entity, so this is the
	 * source document system id. It is a field INSIDE the source document, reached via the ancestor's
	 * document output accessor (`<documentOutput>.ID`, e.g. `ReturnDocument.ID`), not a standalone node
	 * output: the matcher is what combines it with the ancestor's document output. Kept as a single,
	 * documented seam: if a future source exposes its linkable id under a different field, this is the
	 * only place to refine.
	 */
	private const SOURCE_ID_PROPERTY = 'ID';

	private ?RelationManager $relationManager;
	private ?ParentFieldManager $parentFieldManager;

	/** @var array<string, array<string, array>> Per-instance memo of target entity fields, keyed by document type. */
	private array $targetFieldsCache = [];

	/**
	 * Optional seam over the target document field metadata source (defaults to the real
	 * document class). Injected only to drive predefined-field matching against a crafted field set.
	 *
	 * @var (\Closure(array): array)|null
	 */
	private ?\Closure $targetFieldsProvider;

	public function __construct(
		?RelationManager $relationManager = null,
		?ParentFieldManager $parentFieldManager = null,
		?\Closure $targetFieldsProvider = null,
	)
	{
		$this->relationManager = $relationManager;
		$this->parentFieldManager = $parentFieldManager;
		$this->targetFieldsProvider = $targetFieldsProvider;
	}

	public function supports(array $targetDocumentType): bool
	{
		return $this->resolveEntityTypeId($targetDocumentType) > 0;
	}

	/**
	 * The `required` flag is part of the contract but not consumed by the current design-time
	 * transport (autofillMap is a plain fieldCode => expression map); it is computed here and
	 * reserved for future server-side pre-validation of required relation fields.
	 *
	 * @return array<string, array{sourcePropertyId: string, required: bool}>
	 */
	public function resolveRelationFields(
		array $sourceDocumentType,
		array $targetDocumentType,
	): array
	{
		$sourceTypeId = $this->resolveEntityTypeId($sourceDocumentType);
		$targetTypeId = $this->resolveEntityTypeId($targetDocumentType);

		if ($sourceTypeId <= 0 || $targetTypeId <= 0 || $sourceTypeId === $targetTypeId)
		{
			return [];
		}

		// Direction invariant: the source is the parent, the created (target) element is the child that
		// physically holds the link field. If the graph has no such parent -> child edge, the created
		// element does not reference the source, so there is nothing to autofill (fail-open).
		if (!$this->getRelationManager()->areTypesBound(new RelationIdentifier($sourceTypeId, $targetTypeId)))
		{
			return [];
		}

		$sourcePropertyId = self::SOURCE_ID_PROPERTY;

		$customField = $this->resolveCustomParentField($sourceTypeId, $targetTypeId, $sourcePropertyId);
		if ($customField !== [])
		{
			return $customField;
		}

		return $this->resolvePredefinedField($sourceTypeId, $sourcePropertyId, $targetDocumentType);
	}

	/**
	 * Custom/dynamic/SPA relation: the created element carries a PARENT_ID_<sourceTypeId> field.
	 * ParentFieldManager::getParentFieldsInfo walks the graph itself and skips predefined relations,
	 * so a match here means a genuine custom parent edge. Custom parent bindings are optional, so the
	 * field is never required.
	 *
	 * @return array<string, array{sourcePropertyId: string, required: bool}>
	 */
	private function resolveCustomParentField(int $sourceTypeId, int $targetTypeId, string $sourcePropertyId): array
	{
		$parentFieldName = $this->findCustomParentField($sourceTypeId, $targetTypeId);
		if ($parentFieldName === null)
		{
			return [];
		}

		return [
			$parentFieldName => [
				'sourcePropertyId' => $sourcePropertyId,
				'required' => false,
			],
		];
	}

	/**
	 * Name of the target field that holds the id of the source item, or null when the relation is not
	 * carried by a field of the target. Runtime callers filter children of an item by it instead of
	 * fetching every binding first.
	 */
	public function findCustomParentField(int $sourceTypeId, int $targetTypeId): ?string
	{
		if ($sourceTypeId <= 0 || $targetTypeId <= 0 || $sourceTypeId === $targetTypeId)
		{
			return null;
		}

		$parentFields = $this->getParentFieldManager()->getParentFieldsInfo($targetTypeId);
		$parentFieldName = ParentFieldManager::getParentFieldName($sourceTypeId);

		return isset($parentFields[$parentFieldName]) ? $parentFieldName : null;
	}

	/**
	 * Predefined Contact/Company relation: the created element carries a classic UF:crm field
	 * (CONTACT_ID / COMPANY_ID) that references the source. The required flag is taken from the target
	 * document metadata.
	 *
	 * @return array<string, array{sourcePropertyId: string, required: bool}>
	 */
	private function resolvePredefinedField(int $sourceTypeId, string $sourcePropertyId, array $targetDocumentType): array
	{
		if ($sourceTypeId !== \CCrmOwnerType::Contact && $sourceTypeId !== \CCrmOwnerType::Company)
		{
			return [];
		}

		$ownerName = \CCrmOwnerType::ResolveName($sourceTypeId);

		// The canonical link field carries the source id under its own fixed code (CONTACT_ID / COMPANY_ID).
		// Custom UF:crm fields share the same Type/Options profile, so match the exact canonical code to
		// avoid autofilling arbitrary user reference fields; Type/Options stay as a sanity check.
		$canonicalFieldCode = $ownerName . '_ID';

		$result = [];
		foreach ($this->getTargetEntityFields($targetDocumentType) as $code => $field)
		{
			if (
				$code === $canonicalFieldCode
				&& ($field['Type'] ?? null) === 'UF:crm'
				&& ($field['Options'][$ownerName] ?? null) === 'Y'
			)
			{
				$result[$code] = [
					'sourcePropertyId' => $sourcePropertyId,
					'required' => (bool)($field['Required'] ?? false),
				];
			}
		}

		return $result;
	}

	private function resolveEntityTypeId(array $documentType): int
	{
		if (($documentType[0] ?? null) !== 'crm')
		{
			return \CCrmOwnerType::Undefined;
		}

		return \CCrmOwnerType::ResolveID((string)($documentType[2] ?? ''));
	}

	/**
	 * Field metadata of the target bizproc document, keyed by field code.
	 *
	 * @return array<string, array>
	 */
	private function getTargetEntityFields(array $targetDocumentType): array
	{
		if ($this->targetFieldsProvider !== null)
		{
			$fields = ($this->targetFieldsProvider)($targetDocumentType);

			return is_array($fields) ? $fields : [];
		}

		// getEntityFields() is heavy; memoize per target document type (empty result included) so several
		// predefined sources of the same target (e.g. Contact and Company for Deal) resolve fields once.
		$cacheKey = implode(':', $targetDocumentType);
		if (array_key_exists($cacheKey, $this->targetFieldsCache))
		{
			return $this->targetFieldsCache[$cacheKey];
		}

		$targetTypeId = $this->resolveEntityTypeId($targetDocumentType);
		$documentClass = \CCrmBizProcHelper::ResolveDocumentName($targetTypeId);
		if ($documentClass === '' || !method_exists($documentClass, 'getEntityFields'))
		{
			return $this->targetFieldsCache[$cacheKey] = [];
		}

		$fields = $documentClass::getEntityFields((string)($targetDocumentType[2] ?? ''));

		return $this->targetFieldsCache[$cacheKey] = (is_array($fields) ? $fields : []);
	}

	private function getRelationManager(): RelationManager
	{
		return $this->relationManager ?? Container::getInstance()->getRelationManager();
	}

	private function getParentFieldManager(): ParentFieldManager
	{
		return $this->parentFieldManager ?? Container::getInstance()->getParentFieldManager();
	}
}
