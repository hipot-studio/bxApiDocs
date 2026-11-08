<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Activity;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Dto\Complex\ActionObject;
use Bitrix\Bizproc\Activity\Dto\Complex\AvailableBlock;
use Bitrix\Bizproc\Activity\Dto\Complex\BlockAvailability;
use Bitrix\Bizproc\Activity\Dto\Complex\NodeActionCatalogEntry;
use Bitrix\Bizproc\Activity\Dto\Complex\NodeCapabilityCatalog;
use Bitrix\Bizproc\Activity\Enum\ActionSource;
use Bitrix\Bizproc\Activity\Enum\ActivityNodeType;
use Bitrix\Bizproc\Activity\Enum\NodeBlockType;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;

class CapabilityCatalogService
{
	/** @var array<string, NodeCapabilityCatalog|null> In-memory catalog cache keyed by "type:filter:relations" */
	private array $catalogCache = [];

	/** @var array<string, BlockAvailability|null> In-memory blocks-only cache keyed by "type:filter:relations" */
	private array $blocksCache = [];

	public function __construct(
		private readonly ComplexActivityService $complexActivityService,
		private readonly Searcher $searcher,
		private readonly ActionCatalogMap $actionCatalogMap,
	)
	{
	}

	/**
	 * Build a capability catalog for a given complex node activity type.
	 *
	 * @param string $activityType The activity class name (e.g. 'CrmCompanyComplexActivity').
	 * @param bool $filterModuleSupported Whether the runtime can resolve a filter result in this document
	 *     context. Unlike $filterAvailable it also gates a declared filter block, so it is required: a
	 *     caller that omitted it would reopen the surface the gate exists to close.
	 * @param bool $filterAvailable Legacy filter rule, applied only to a node that declares no
	 *     availableBlocks: a node that declares the filter block offers it regardless of this flag.
	 * @param bool $relationsAvailable Whether the relations block is supported (complexNodeConnections feature).
	 * @param array|null $documentType Document context: actions locked in this context are excluded.
	 * @return NodeCapabilityCatalog|null
	 */
	public function getCatalogForNode(
		string $activityType,
		bool $filterModuleSupported,
		bool $filterAvailable = false,
		bool $relationsAvailable = false,
		?array $documentType = null,
	): ?NodeCapabilityCatalog
	{
		$activityCode = strtolower($activityType);
		// In-memory cache avoids rebuilding the catalog on every port.
		$cacheKey = $this->buildCacheKey(
			$activityCode,
			$filterAvailable,
			$relationsAvailable,
			$documentType,
			$filterModuleSupported,
		);
		if (array_key_exists($cacheKey, $this->catalogCache))
		{
			return $this->catalogCache[$cacheKey];
		}

		$description = $this->complexActivityService->getActivityDescriptionByCode($activityCode);
		if (!$description)
		{
			$this->catalogCache[$cacheKey] = null;

			return null;
		}

		$availableBlocks = $this->resolveAvailableBlocks(
			$description,
			$filterAvailable,
			$relationsAvailable,
			$filterModuleSupported,
		);

		$nodeActionCollection = $this->complexActivityService->getCorrespondingNodeActionActivityByName(
			$activityCode,
			$documentType,
		);
		$nodeActionDictionary = $description->getComplexActivitySettings()?->actionDictionary;
		$actions = [];
		foreach ($nodeActionCollection as $nodeAction)
		{
			$normalizedCode = $this->searcher->normalizeActivityCode($nodeAction->getClass());
			$group = $nodeActionDictionary?->get($normalizedCode)?->group?->value;
			$handlesDocument = $nodeAction->getNodeActionSettingsDto()?->handlesDocument ?? false;

			// Enrich the already-declared action with its own classification (Q-AFC-1 (c)):
			// area/object/source come from the activity declaration, gated by installed module (scope 7).
			$classification = $this->actionCatalogMap->getAvailableClassification($normalizedCode);
			$areas = $objects = $sources = null;
			if ($classification !== null)
			{
				$areas = [$classification->area->toArray()];
				$objects = array_map(
					static fn(ActionObject $object) => $object->toArray(),
					$classification->objects,
				);
				$sources = $this->resolveSources($handlesDocument);
				$group ??= $classification->group->value;
			}

			$actions[] = new NodeActionCatalogEntry(
				id: $normalizedCode,
				title: $nodeAction->getName(),
				handlesDocument: $handlesDocument,
				group: $group,
				areas: $areas,
				objects: $objects,
				sources: $sources,
			);
		}

		$nodeType = $description->getNodeType() ?? ActivityNodeType::COMPLEX->value;

		$catalog = new NodeCapabilityCatalog(
			activityCode: $activityCode,
			nodeType: strtolower($nodeType),
			availableBlocks: $availableBlocks,
			actions: $actions,
			meta: null,
		);

		$this->catalogCache[$cacheKey] = $catalog;

		return $catalog;
	}

	/**
	 * Return only the available-blocks descriptor for a node without loading its full action list.
	 * Used in loadSettings to avoid a redundant getCorrespondingNodeActionActivityByName() call.
	 *
	 * @param string $activityType
	 * @param bool $filterModuleSupported Whether the runtime can resolve a filter result in this document
	 *     context. Unlike $filterAvailable it also gates a declared filter block, so it is required; see
	 *     getCatalogForNode().
	 * @param bool $filterAvailable Legacy filter rule, applied only to a node without a declaration.
	 * @param bool $relationsAvailable
	 * @param array|null $documentType Only participates in the cache key: blocks are
	 *     context-free, but the key must match getCatalogForNode() to reuse its cache.
	 * @param ActivityDescription|null $description Already-resolved description of the same activity:
	 *     saves the uncached Searcher::searchByCode() lookup when the caller holds one.
	 * @return BlockAvailability|null null when the activity is not found
	 */
	public function getAvailableBlocksForNode(
		string $activityType,
		bool $filterModuleSupported,
		bool $filterAvailable = false,
		bool $relationsAvailable = false,
		?array $documentType = null,
		?ActivityDescription $description = null,
	): ?BlockAvailability
	{
		$activityCode = strtolower($activityType);
		// If the full catalog is already cached, reuse its availableBlocks.
		$cacheKey = $this->buildCacheKey(
			$activityCode,
			$filterAvailable,
			$relationsAvailable,
			$documentType,
			$filterModuleSupported,
		);
		if (array_key_exists($cacheKey, $this->catalogCache))
		{
			return $this->catalogCache[$cacheKey]?->availableBlocks;
		}

		if (array_key_exists($cacheKey, $this->blocksCache))
		{
			return $this->blocksCache[$cacheKey];
		}

		$description ??= $this->complexActivityService->getActivityDescriptionByCode($activityCode);
		if (!$description)
		{
			$this->blocksCache[$cacheKey] = null;

			return null;
		}

		$blocks = $this->resolveAvailableBlocks(
			$description,
			$filterAvailable,
			$relationsAvailable,
			$filterModuleSupported,
		);

		$this->blocksCache[$cacheKey] = $blocks;

		return $blocks;
	}

	/**
	 * Relations-block availability for canvas/catalog consumers, gated the same way as
	 * applyRuntimeGates(): a declared relations block stays available only when the node declares
	 * the relation action its cards are filled with and the runtime flag allows it, so the canvas
	 * never diverges from the settings panel. The verdict is two-valued: a node whose descriptor
	 * says nothing about relations has none, so no consumer is left with an undecided state to
	 * interpret on its own.
	 *
	 * @param ActivityDescription $description
	 * @param bool $relationsAvailable Runtime gate (complexNodeConnections feature).
	 * @return bool
	 */
	public function getRelationsAvailabilityForNode(
		ActivityDescription $description,
		bool $relationsAvailable,
	): bool
	{
		$settings = $description->getComplexActivitySettings();
		if ($settings === null || $settings->relationAction === null)
		{
			return false;
		}

		if ($settings->availableBlocks?->isAvailable(NodeBlockType::RELATIONS) !== true)
		{
			return false;
		}

		return $relationsAvailable;
	}

	private function buildCacheKey(
		string $activityCode,
		bool $filterAvailable,
		bool $relationsAvailable,
		?array $documentType,
		bool $filterModuleSupported,
	): string
	{
		return $activityCode
			. ':' . ($filterAvailable ? '1' : '0')
			. ':' . ($relationsAvailable ? '1' : '0')
			. ':' . implode('|', $documentType ?? [])
			. ':' . ($filterModuleSupported ? '1' : '0')
		;
	}

	/**
	 * Supported object sources (PRD §16.1) for an action. An action that operates on a document
	 * offers the full set; one that does not (e.g. a notification) offers every source except
	 * CURRENT_OBJECT, since there is no current document to read.
	 *
	 * @return list<string>
	 */
	private function resolveSources(bool $handlesDocument): array
	{
		$sources = ActionSource::cases();
		if (!$handlesDocument)
		{
			$sources = array_filter(
				$sources,
				static fn(ActionSource $source) => $source !== ActionSource::CURRENT_OBJECT,
			);
		}

		return array_values(array_map(static fn(ActionSource $source) => $source->value, $sources));
	}

	/**
	 * Resolve availableBlocks from explicit declaration or fall back to the legacy default.
	 *
	 * The relations block is offered only to a node that declares it next to the relation action its
	 * cards are filled with: without the action the card has no action to select and could never be
	 * filled, and without the declared block the node has no relations at all. A node without an
	 * availableBlocks declaration therefore gets the legacy default with relations off - whether it
	 * declares a relation action or not - which is the verdict getRelationsAvailabilityForNode()
	 * reports for the very same node, so the panel never offers a block the save path would discard.
	 *
	 * @param ActivityDescription $description Description of the node itself: it decides the declaration
	 *     path and, through Properties.BaseSettingsAction, the base-settings block.
	 * @param bool $filterAvailable Decides the filter block only for a node without a declaration.
	 * @param bool $relationsAvailable Runtime gate, applied to a declared relations block alone.
	 * @param bool $filterModuleSupported Gates a declared filter block; see applyRuntimeGates().
	 * @return BlockAvailability
	 */
	private function resolveAvailableBlocks(
		ActivityDescription $description,
		bool $filterAvailable,
		bool $relationsAvailable,
		bool $filterModuleSupported,
	): BlockAvailability
	{
		$settings = $description->getComplexActivitySettings();

		$blocks = ($settings !== null && $settings->availableBlocks !== null)
			? $this->applyRuntimeGates(
				$settings->availableBlocks,
				$relationsAvailable && $settings->relationAction !== null,
				$filterModuleSupported,
			)
			: $this->buildLegacyDefaultAvailableBlocks($filterAvailable)
		;

		$properties = $description->get('PROPERTIES');
		$isComplexNode = strtolower((string)$description->getNodeType()) === ActivityNodeType::COMPLEX->value;
		$hasBaseSettingsAction = is_array($properties)
			&& (string)($properties['BaseSettingsAction'] ?? '') !== '';

		$baseSettings = $blocks->get(NodeBlockType::BASE_SETTINGS);
		if ($isComplexNode && !$hasBaseSettingsAction && $baseSettings?->available)
		{
			// A complex node has no settings of its own: the block renders the form of the action named
			// in Properties.BaseSettingsAction, so without that name it would open empty. Returns a copy:
			// the declared descriptor is never mutated.
			$blocks = BlockAvailability::fromArrayData($blocks->toArray())->set(
				NodeBlockType::BASE_SETTINGS,
				new AvailableBlock(available: false, constraints: $baseSettings->constraints),
			);
		}

		return $blocks;
	}

	/**
	 * Intersect an explicit declaration with the runtime gates of the two surfaces that have one.
	 *
	 * A declared relations block stays available only while the complexNodeConnections feature allows
	 * it, because a relation card is edited by a surface that feature owns. A declared filter block
	 * stays available only while the document context has a filter-result resolver: the editor may only
	 * offer a selection the runtime is able to resolve, and a node declaring the filter block would
	 * otherwise show it on a document whose module has no provider at all. The filter rollout flag is
	 * still deliberately not applied here - it decides only for nodes without a declaration
	 * (buildLegacyDefaultAvailableBlocks()).
	 *
	 * Returns a copy: the declared descriptor is never mutated.
	 */
	private function applyRuntimeGates(
		BlockAvailability $declared,
		bool $relationsAvailable,
		bool $filterModuleSupported,
	): BlockAvailability
	{
		$blocks = BlockAvailability::fromArrayData($declared->toArray());

		$gates = [
			[NodeBlockType::RELATIONS, $relationsAvailable],
			[NodeBlockType::FILTER, $filterModuleSupported],
		];

		foreach ($gates as [$blockType, $isAllowed])
		{
			$declaredBlock = $blocks->get($blockType);
			if ($declaredBlock !== null && $declaredBlock->available && !$isAllowed)
			{
				$blocks->set(
					$blockType,
					new AvailableBlock(available: false, constraints: $declaredBlock->constraints),
				);
			}
		}

		return $blocks;
	}

	/**
	 * Build legacy-compatible default BlockAvailability for nodes without an explicit declaration:
	 * base-settings+condition+action+output always available; filter by filterSupported rule; relations
	 * off, because an undeclared block is no block; group/storages = false.
	 * The set itself lives on the descriptor DTO, so a node declaring the same default (the installed
	 * CRM complex nodes do) reads it from one place instead of repeating the map - and a node that does
	 * declare relations passes them in there, not through this default.
	 */
	private function buildLegacyDefaultAvailableBlocks(bool $filterAvailable): BlockAvailability
	{
		return BlockAvailability::legacyDefault($filterAvailable);
	}
}
