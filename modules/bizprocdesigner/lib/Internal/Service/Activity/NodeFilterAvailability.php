<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Service\Activity;

use Bitrix\Bizproc\Activity\Dto\Complex\AvailableBlock;
use Bitrix\Bizproc\Activity\Dto\Complex\BlockAvailability;
use Bitrix\Bizproc\Activity\Enum\NodeBlockType;
use Bitrix\Bizproc\Internal\Service\Container as BizprocContainer;
use Bitrix\BizprocDesigner\Internal\Config\Feature;

/**
 * Single source of truth for "does this complex node offer the filter block".
 *
 * Every surface used to answer it on its own and they disagreed: loadSettings resolved the module from
 * the node's fixed document type only, while the capability-catalog endpoint and the save-time rule
 * validation also fell back to the edited template's document type. A filter that
 * {@see \Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ValidateSingleRuleCommand} accepted
 * could therefore be hidden by the settings panel - and the other way round, a filter the panel showed
 * could fail to save with ERR-BLOCK-NOT-AVAILABLE. All of them now go through this class.
 */
final class NodeFilterAvailability
{
	/**
	 * Effective document type of a node: its own fixed type wins, otherwise the edited template's.
	 *
	 * @param array|null $fixedDocumentType Type the node locks its sub-actions to; null when it has none.
	 * @param array $documentType Document type of the edited template; empty on legacy calls that omit it.
	 */
	public function resolveEffectiveDocumentType(?array $fixedDocumentType, array $documentType): ?array
	{
		if ($fixedDocumentType !== null)
		{
			return $fixedDocumentType;
		}

		return $documentType === [] ? null : $documentType;
	}

	/**
	 * Whether the runtime can resolve a filter result for the module of the effective document type,
	 * i.e. that module has a registered filter-result resolver.
	 *
	 * This half of the gate holds for every node, declared or not: the filter editor may only offer a
	 * selection the runtime is able to resolve. A node that declares the filter block bypasses the
	 * rollout flag, never the resolver - without one the panel used to open the legacy settings dialog
	 * with an empty activity type and print "Bad activity type!" into the block.
	 *
	 * The result is what {@see \Bitrix\Bizproc\Internal\Service\Activity\CapabilityCatalogService}
	 * intersects the declared filter block with, so every surface publishing availableBlocks must pass
	 * it: loadSettings, the capability-catalog endpoint, the save-time rule validation and the AI catalog.
	 */
	public function supportsModule(?array $fixedDocumentType, array $documentType): bool
	{
		$effectiveDocumentType = $this->resolveEffectiveDocumentType($fixedDocumentType, $documentType);

		return BizprocContainer::instance()
			->getFilterResultPropertyResolverRegistry()
			->supportsModule((string)($effectiveDocumentType[0] ?? ''))
		;
	}

	/**
	 * Runtime gate of the filter surface: the rollout flag plus {@see self::supportsModule()}. It decides
	 * the block only for a node that declares no availableBlocks - a node that declares the filter block
	 * is gated by module support alone
	 * (@see \Bitrix\Bizproc\Internal\Service\Activity\CapabilityCatalogService).
	 */
	public function isRuntimeAvailable(?array $fixedDocumentType, array $documentType): bool
	{
		return Feature::instance()->isNodeFilterAvailable()
			&& $this->supportsModule($fixedDocumentType, $documentType)
		;
	}

	/**
	 * The one answer every surface publishes: the node's block descriptor decides, and the runtime gate
	 * is the fallback for a node the capability catalog cannot describe at all (no complex settings, so
	 * no descriptor to read). The descriptor arrives already intersected with module support, so this
	 * answer needs no extra gate of its own.
	 *
	 * @param BlockAvailability|null $availableBlocks Descriptor of the node, null when it has none.
	 * @param bool $runtimeAvailable Result of {@see self::isRuntimeAvailable()} for the same node.
	 */
	public function isAvailableForNode(?BlockAvailability $availableBlocks, bool $runtimeAvailable): bool
	{
		return $availableBlocks?->isAvailable(NodeBlockType::FILTER) ?? $runtimeAvailable;
	}

	/**
	 * The blocks the editor offers to add: while the rollout flag is off the filter is not offered, a node that
	 * declares it included. Only the offer - the save path keeps {@see self::isAvailableForNode()}, so a filter
	 * saved earlier still passes validation.
	 */
	public function hideFilterUntilRolledOut(BlockAvailability $availableBlocks): BlockAvailability
	{
		$filter = $availableBlocks->get(NodeBlockType::FILTER);
		if ($filter === null || !$filter->available || Feature::instance()->isNodeFilterAvailable())
		{
			return $availableBlocks;
		}

		return BlockAvailability::fromArrayData($availableBlocks->toArray())->set(
			NodeBlockType::FILTER,
			new AvailableBlock(available: false, constraints: $filter->constraints),
		);
	}
}
