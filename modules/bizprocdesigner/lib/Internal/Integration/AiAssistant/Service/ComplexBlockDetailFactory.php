<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Dto\Complex\BlockAvailability;
use Bitrix\Bizproc\Activity\Enum\ActivityNodeType;
use Bitrix\Bizproc\Activity\Enum\NodeBlockType;
use Bitrix\Bizproc\Internal\Entity\Activity\Result\ActivityAiDescriptionResult;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingCollection;
use Bitrix\Bizproc\Internal\Service\Container as BizprocContainer;
use Bitrix\BizprocDesigner\Internal\Config\Feature;
use Bitrix\BizprocDesigner\Internal\Entity\ComplexBlockDetail;
use Bitrix\BizprocDesigner\Internal\Entity\ComplexNodeAction;
use Bitrix\BizprocDesigner\Internal\Service\Activity\NodeFilterAvailability;
use Bitrix\BizprocDesigner\Internal\Service\Container;

/**
 * Builds the complex-node detail (DTO-02) for a block: the dictionary of allowed sub-actions,
 * the fixed document type and the node-filter availability in the current environment.
 *
 * Shared by the REST catalog ({@see AgentBlockCatalogService}) and the Marta catalog
 * ({@see BlockDescriptionService}) so both derive the detail from the single source of truth used by
 * the manual editor ({@see \Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService}) -
 * never from a parallel AI-side description. Mirrors the logic of the manual editor's
 * {@see \Bitrix\BizprocDesigner\Infrastructure\Controller\Activity\Complex::loadSettingsAction()}
 * without copying it.
 */
final class ComplexBlockDetailFactory
{
	/**
	 * Returns the complex detail for the block, or `null` when the block is not a COMPLEX node
	 * (behaviour is unchanged for simple/trigger/operators blocks, which never carry a complex detail).
	 *
	 * @param string $blockType activity code of the block (any case; normalized internally)
	 * @param string|null $nodeType the block's NODE_TYPE (from the resolved description or the raw catalog array)
	 * @param array|null $documentType template document type context; gates context-locked (LOCKED) actions
	 */
	public function build(string $blockType, ?string $nodeType, ?array $documentType = null): ?ComplexBlockDetail
	{
		if ($nodeType !== ActivityNodeType::COMPLEX->value)
		{
			return null;
		}

		$complexActivityService = BizprocContainer::instance()->getComplexActivityService();
		$searcher = BizprocContainer::instance()->getActivitySearcherService();

		$complexActivityCode = mb_strtolower($blockType);

		$description = $complexActivityService->getActivityDescriptionByCode($complexActivityCode);
		$actionDictionary = $description?->getComplexActivitySettings()?->actionDictionary;

		$fixedDocumentType = $complexActivityService->getFixedDocumentTypeForNodeAction($blockType);

		// Same context rule as the manual editor (Complex::loadSettingsAction): the fixed document
		// wins, otherwise the template's document type gates context-locked (LOCKED) actions.
		$filterAvailability = Container::getNodeFilterAvailability();
		$effectiveDocumentType = $filterAvailability->resolveEffectiveDocumentType($fixedDocumentType, $documentType ?? []);

		$nodeActions = [];
		foreach ($complexActivityService->getCorrespondingNodeActionActivityByName($complexActivityCode, $effectiveDocumentType) as $nodeActivity)
		{
			/** @var ActivityDescription $nodeActivity */
			$normalizedCode = $searcher->normalizeActivityCode((string)$nodeActivity->getClass());
			$nodeAction = $actionDictionary?->get($normalizedCode);
			$handlesDocument = $nodeActivity->getNodeActionSettingsDto()?->handlesDocument ?? false;
			$subActionDescription = $this->describeSubAction($normalizedCode, $effectiveDocumentType);

			$nodeActions[] = new ComplexNodeAction(
				activityCode: $nodeAction?->activityCode ?? $normalizedCode,
				title: $nodeActivity->getName(),
				handlesDocument: $handlesDocument,
				sort: $nodeAction?->sort ?? ($nodeActivity->getSort() ?? 0),
				presetId: $nodeAction?->presetId,
				settingsSchema: $subActionDescription?->settings ?? new SettingCollection(),
				skippedSettings: $this->namesAllowedBesideTheSchema($subActionDescription),
				documentSchema: $this->buildDocumentSchema($handlesDocument, $fixedDocumentType),
			);
		}

		$runtimeFilterAvailable = $filterAvailability->isRuntimeAvailable($fixedDocumentType, $documentType ?? []);
		$availableBlocks = $this->resolveAvailableBlocks(
			$filterAvailability,
			$blockType,
			$fixedDocumentType,
			$documentType ?? [],
			$description,
			$runtimeFilterAvailable,
		);

		return new ComplexBlockDetail(
			nodeActions: $nodeActions,
			fixedDocumentType: $fixedDocumentType,
			filterSupported: $filterAvailability->isAvailableForNode($availableBlocks, $runtimeFilterAvailable),
			conditionSupported: $availableBlocks?->isAvailable(NodeBlockType::CONDITION) ?? true,
		);
	}

	/**
	 * Description of a sub-action (DTO-04), built by the SAME domain service that describes ordinary nodes
	 * ({@see \Bitrix\Bizproc\Service\AiDescription::getActivityDescription}) - never a parallel AI description
	 * of the sub-action. It carries both halves the catalog needs: the settings schema the agent may write and
	 * the names it may keep without them being a schema violation. The document type is the node's effective one
	 * ({@see NodeFilterAvailability::resolveEffectiveDocumentType}): its fixed document when it has one
	 * (e.g. `['crm', 'CCrmDocumentDeal', 'DEAL']`), the edited template's otherwise - the same context
	 * the sub-action dictionary above is resolved with.
	 *
	 * Failure isolation: no document context at all, or a non-successful Result (an unresolvable/broken
	 * sub-action), yields `null` - an empty schema for that one sub-action instead of a broken
	 * ComplexBlockDetail. An empty schema is also the valid value for an HTML-only sub-action.
	 */
	private function describeSubAction(string $activityCode, ?array $documentType): ?ActivityAiDescriptionResult
	{
		if ($documentType === null)
		{
			return null;
		}

		$description = \CBPRuntime::getRuntime()
			->getAiDescriptionService()
			->getActivityDescription($activityCode, $documentType)
		;

		return $description instanceof ActivityAiDescriptionResult ? $description : null;
	}

	/**
	 * The names a sub-step may carry beside its typed schema: the ones the description could not express and the
	 * ones the activity declares as carried from the published template. An ordinary block judges a name by the
	 * same union ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentSettingNameValidator::validate()}),
	 * and a sub-step has to get the same verdict: the AI sub-action carries `pinnedContext` that way, and
	 * refusing it would cost the agent the whole template.
	 *
	 * @return list<string>
	 */
	private function namesAllowedBesideTheSchema(?ActivityAiDescriptionResult $description): array
	{
		if ($description === null)
		{
			return [];
		}

		return array_values(array_unique(
			array_merge($description->skippedSettings, $description->carriedSettings)
		));
	}

	/**
	 * Machine-readable contract of the sub-action's `document` construction field - the bizproc expression
	 * `{=<sourceBlockId>:<propertyId>}` the manual editor stores for a document-handling sub-action. Only the
	 * FORMAT is described: the concrete value set is a function of the graph topology and thus uncomputable in
	 * the catalog, so the agent assembles the value from the ancestor block's DOCUMENT return property
	 * (`returnProperties`, P11). The `documentType` mirrors the complex node's fixed document (e.g.
	 * `['crm', 'CCrmDocumentDeal', 'DEAL']`); the source block must match by module ([0]).
	 *
	 * Symmetric to {@see self::describeSubAction}: a sub-action that does not handle the document, or a complex node
	 * without a fixed document, carries no document contract (`null`) - the key is not emitted, which is not a
	 * regression.
	 *
	 * @return array{
	 *     required: bool,
	 *     type: string,
	 *     format: string,
	 *     valueSource: string,
	 *     documentType: array,
	 * }|null
	 */
	private function buildDocumentSchema(bool $handlesDocument, ?array $fixedDocumentType): ?array
	{
		if (!$handlesDocument || $fixedDocumentType === null)
		{
			return null;
		}

		return [
			'required' => true, // == handlesDocument
			'type' => 'document',
			'format' => 'bizprocExpression', // a string like {=<sourceBlockId>:<propertyId>}
			'valueSource' => 'ancestorReturnProperty', // DOCUMENT output of an ancestor block (from returnProperties, P11)
			'documentType' => $fixedDocumentType, // [module, class, type]; source block matches by module [0]
		];
	}

	/**
	 * The block composition the node offers, as
	 * {@see \Bitrix\BizprocDesigner\Infrastructure\Controller\Activity\Complex::loadSettingsAction()}
	 * publishes it: the node's block descriptor decides, and a node without one falls back to the legacy
	 * default. `null` when the activity is not found.
	 *
	 * The two flags of the detail are read off one answer, because the save path judges both by this same
	 * descriptor ({@see \Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ValidateSingleRuleCommand})
	 * - computing them apart would let the agent build a graph the save path refuses. The filter flag also
	 * carries the runtime gate of {@see NodeFilterAvailability}, which is the fallback for a node with no
	 * declaration; the condition block has no such gate.
	 */
	private function resolveAvailableBlocks(
		NodeFilterAvailability $filterAvailability,
		string $blockType,
		?array $fixedDocumentType,
		array $documentType,
		?ActivityDescription $description,
		bool $runtimeFilterAvailable,
	): ?BlockAvailability
	{
		// The relations flag does not affect the filter block; it is passed as the other surfaces pass it
		// so the descriptor lookup hits the catalog cache instead of rebuilding it. The description
		// build() already resolved is reused instead of a second uncached searchByCode() lookup.
		return BizprocContainer::instance()->getCapabilityCatalogService()->getAvailableBlocksForNode(
			activityType: $blockType,
			filterAvailable: $runtimeFilterAvailable,
			relationsAvailable: Feature::instance()->areComplexNodeConnectionsAvailable(),
			documentType: $filterAvailability->resolveEffectiveDocumentType($fixedDocumentType, $documentType),
			filterModuleSupported: $filterAvailability->supportsModule($fixedDocumentType, $documentType),
			description: $description,
		);
	}
}
