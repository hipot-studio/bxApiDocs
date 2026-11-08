<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Command\Activity\Complex;

use Bitrix\Bizproc\Activity\Dto\Complex\NodeActionCatalogEntry;
use Bitrix\Bizproc\Activity\Dto\Complex\NodeCapabilityCatalog;
use Bitrix\Bizproc\Activity\Enum\NodeBlockType;
use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\PortRuleDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ActionExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\BaseExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\BaseSettingsExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConstructionDto;
use Bitrix\BizprocDesigner\Infrastructure\Enum\ConstructionType;
use Bitrix\BizprocDesigner\Internal\Command\AbstractCommand;
use Bitrix\BizprocDesigner\Internal\Config\Feature;
use Bitrix\BizprocDesigner\Internal\Service\Activity\ActionDocumentValue;
use Bitrix\BizprocDesigner\Internal\Service\Activity\BaseSettingsPayload;
use Bitrix\BizprocDesigner\Internal\Service\Container as DesignerContainer;
use Bitrix\BizprocDesigner\Internal\Trait\NodeActionResolver;
use Bitrix\BizprocDesigner\Public\Service\Activity\TriggerUpgradeResolver;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Main\Validation\ValidationService;

class ValidateSingleRuleCommand extends AbstractCommand
{
	use NodeActionResolver;

	/**
	 * Construction types a relation-port card may carry, mirroring what the rules toolbar offers on a
	 * relation port. The general settings are the head block of a relation input: they stand for the very
	 * action the node declares for its relations, so they arrive bound to it and become a child activity of
	 * their own - never a merge into the properties of the host ({@see self::validateBaseSettings()}). A
	 * `filter` stays out: it belongs to an input port of the node. Relation cards are validated without a
	 * node capability catalog on purpose - the relation action is declared apart from actionDictionary and
	 * is therefore absent from the node catalog, so catalog membership would reject valid cards. This list
	 * is what replaces the block-availability check for those cards; the action itself is validated against
	 * the node's declared relation action in the controller.
	 */
	public const RELATION_PORT_CONSTRUCTION_TYPES = [
		ConstructionType::BASE_SETTINGS,
		ConstructionType::IF_CONDITION,
		ConstructionType::AND_CONDITION,
		ConstructionType::OR_CONDITION,
		ConstructionType::ACTION,
		ConstructionType::OUTPUT,
	];

	/**
	 * Construction types the reserved rules container of a node without input ports
	 * ({@see \Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService::PORTLESS_RULES_KEY}) may
	 * carry. An action becomes a child activity of the node, whose chain is entered through the start the
	 * converter addresses by that very reserved key; base settings and a filter are serialized into
	 * properties of the host node and need no child at all. An output stays out - it wires a child to an
	 * output port of the rules, and a node without an input port declares none of those. Whether a block is
	 * offered at all stays with the node descriptor; this list only bounds what the container may hold.
	 */
	public const PORTLESS_RULE_CONSTRUCTION_TYPES = [
		ConstructionType::BASE_SETTINGS,
		ConstructionType::IF_CONDITION,
		ConstructionType::AND_CONDITION,
		ConstructionType::OR_CONDITION,
		ConstructionType::FILTER,
		ConstructionType::ACTION,
	];

	private ValidationService $validationService;
	private readonly Searcher $searcher;

	/** @var NodeCapabilityCatalog|false|null Cache: null=not loaded, false=not found */
	private NodeCapabilityCatalog|false|null $catalogCache = null;

	/** @var array<string, true>|null Normalized action id hash-map built once from catalogCache, null until built */
	private ?array $catalogActionIdMap = null;

	/**
	 * @param PortRuleDto $portRuleDto
	 * @param Searcher|null $searcher
	 * @param string|null $activityType Host complex activity type (e.g. 'CrmCompanyComplexActivity').
	 *   When provided, the command validates block types and action IDs against the node's capability catalog.
	 * @param array|null $documentType Document type context used to resolve filterAvailable/relationsAvailable.
	 * @param list<ConstructionType>|null $allowedConstructionTypes Explicit allowlist of construction types
	 *   the rule may carry. Null keeps the catalog-driven behaviour, where block availability comes from the
	 *   node descriptor; relation ports pass self::RELATION_PORT_CONSTRUCTION_TYPES instead.
	 * @param array|null $activityProperties Properties of the host node. A trigger resolves the document type
	 *   of its event from them ({@see \Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService::getPublishedDocumentTypeForNode()}),
	 *   so the catalog this command validates against is built in the same document context the settings
	 *   panel published - otherwise a card the panel offered would be rejected here.
	 * @param bool $isRelationPort Whether the rule belongs to a relation port. The general settings of such a
	 *   port stand for the relation action the node declares, so a construction bound to no action at all is
	 *   refused: it would be host-merged, and a relation container merges nothing into its host.
	 */
	public function __construct(
		public readonly PortRuleDto $portRuleDto,
		?Searcher $searcher = null,
		public readonly ?string $activityType = null,
		public readonly ?array $documentType = null,
		public readonly ?array $allowedConstructionTypes = null,
		public readonly ?array $activityProperties = null,
		public readonly bool $isRelationPort = false,
	)
	{
		Loader::requireModule('bizproc');

		$this->validationService = ServiceLocator::getInstance()->get('main.validation.service');
		$this->searcher = $searcher ?? Container::instance()->getActivitySearcherService();
	}

	protected function execute(): ValidateSingleRuleCommandResult
	{
		foreach ($this->portRuleDto->rules as $rule)
		{
			$result = $this->validateConstructions($rule->constructions);
			if (!$result->isSuccess())
			{
				return new ValidateSingleRuleCommandResult(isFilled: false);
			}
		}

		return new ValidateSingleRuleCommandResult(isFilled: true);
	}

	/**
	 * @param list<ConstructionDto> $constructions
	 * @return Result
	 */
	protected function validateConstructions(array $constructions): Result
	{
		$allowedTypesResult = $this->validateAllowedConstructionTypes($constructions);
		if (!$allowedTypesResult->isSuccess())
		{
			return $allowedTypesResult;
		}

		// Server-side uniqueness guard: at most one base-settings construction per rule.
		$baseSettingsCount = 0;
		foreach ($constructions as $construction)
		{
			if ($construction->constructionType === ConstructionType::BASE_SETTINGS)
			{
				$baseSettingsCount++;
				if ($baseSettingsCount > 1)
				{
					$result = new Result();
					$result->addError(new Error(
						Loc::getMessage('BIZPROCDESIGNER_COMMAND_VALIDATE_SINGLE_RULE_BASE_SETTINGS_DUPLICATE')
						?? 'Only one base-settings construction is allowed per node.',
						'ERR-BASE-SETTINGS-DUPLICATE',
					));

					return $result;
				}
			}
		}

		foreach ($constructions as $construction)
		{
			$expression = $construction->expression;

			if ($this->activityType !== null)
			{
				$blockTypeResult = $this->validateConstructionBlockType($construction);
				if (!$blockTypeResult->isSuccess())
				{
					return $blockTypeResult;
				}
			}

			// Base-settings is optional (absence is valid); only validate when present.
			if (
				$construction->constructionType === ConstructionType::BASE_SETTINGS
				&& $expression instanceof BaseSettingsExpressionDto
			)
			{
				$baseSettingsResult = $this->validateBaseSettings($expression);
				if (!$baseSettingsResult->isSuccess())
				{
					return $baseSettingsResult;
				}

				continue;
			}

			$result = $this->validateExpression($expression, $construction->constructionType);
			if (!$result->isSuccess())
			{
				return $result;
			}
		}

		return new Result();
	}

	/**
	 * Reject any construction type outside the allowlist, when one is set. A rule validated without a node
	 * capability catalog gets no block-availability check at all, so a direct payload could otherwise carry
	 * a `base-settings` (host-merged into the node's own Properties) or a `filter` (serialized into
	 * Properties.FilterSettings) on a port where neither block exists.
	 *
	 * @param list<ConstructionDto> $constructions
	 */
	private function validateAllowedConstructionTypes(array $constructions): Result
	{
		$result = new Result();
		if ($this->allowedConstructionTypes === null)
		{
			return $result;
		}

		foreach ($constructions as $construction)
		{
			if (in_array($construction->constructionType, $this->allowedConstructionTypes, true))
			{
				continue;
			}

			$result->addError(new Error(
				Loc::getMessage('BIZPROCDESIGNER_COMMAND_VALIDATE_SINGLE_RULE_BLOCK_NOT_ALLOWED_ON_PORT', [
					'#BLOCK#' => $construction->constructionType->value,
				]) ?? sprintf(
					'Block type "%s" is not allowed on this port.',
					$construction->constructionType->value,
				),
				'ERR-BLOCK-NOT-ALLOWED-ON-PORT',
			));

			return $result;
		}

		return $result;
	}

	/**
	 * Validate a present BASE_SETTINGS construction. Required-field checks are done by the
	 * backing node-action's own ValidateProperties during save (internalizeNodeActionProperties).
	 */
	private function validateBaseSettings(BaseSettingsExpressionDto $expression): Result
	{
		$result = new Result();
		$activityData = $expression->activityData ?? [];
		$actualType = (string)($activityData['Type'] ?? '');
		$isBoundToAction = $expression->actionId !== null && $expression->actionId !== '';

		// The head block of a relation input speaks for the action of that input and for nothing else. A
		// construction arriving there bound to no action would take the host-merge route and write the
		// properties it carries into the node itself, so it is refused instead. Which action it is bound to
		// is the controller's to answer: only there is the relation action the node declares known.
		if ($this->isRelationPort && !$isBoundToAction)
		{
			$result->addError(new Error(
				'Base settings of a relation port must be bound to the relation action of the node.',
				'ERR-BASE-SETTINGS-NOT-BOUND-ON-RELATION-PORT',
			));

			return $result;
		}

		// Node-action-backed base-settings (actionId !== null): it describes an action of its own and is
		// converted into a child activity, so its document answers to the rules an ACTION answers to, and
		// its action answers to the catalog of the node - the declared-vs-actual payload guard included.
		if ($isBoundToAction)
		{
			// The editor puts the block on the node the moment the node is opened
			// (initBaseSettingsIfNeeded), so a form nobody ever filled is the normal state of a node
			// nobody has touched. The converter builds no child of such a proxy, and nothing of the
			// form may be asked of it here either - otherwise the very first save of the node would
			// refuse it over a document its author was never shown. The action itself is another
			// matter: it is declared by the node and not filled in by anyone, so its catalog
			// membership is checked whether the form was filled or not.
			if (!BaseSettingsPayload::isFormEmpty($expression))
			{
				$this->validateProxyDocument($expression, $result);
			}

			if ($this->activityType !== null)
			{
				$this->validateActionCatalogMembership($expression->actionId, $actualType, $result);
			}

			return $result;
		}

		if ($this->activityType === null)
		{
			return $result;
		}

		// Bound to a node-action by its type alone: the converter builds it as a proxy child - or drops it
		// on the portless container - and never host-merges it, so the guard below must not speak about it.
		// A payload carrying the class of the host is the node's own form and is host-merged whatever its
		// class is able to serve as, so it goes to that guard - which checks that very equality.
		if (
			!$this->isHostOwnBaseSettings($expression, $actualType, (string)$this->activityType)
			&& $this->isNodeActionBackingType($actualType)
		)
		{
			return $result;
		}

		// Host-merged construction: its payload describes the node itself, so the class it carries has to
		// be the class of the host activity the request names - or the upgrade target that class resolves
		// to, since the form of a deprecated node is built from the latter. The host type is the anchor on
		// purpose: read off the construction DTO alone, the "current" class would be the client's to state,
		// and the properties of a foreign class would be merged into the node instead of being refused.
		$this->validateHostMergedBaseSettingsType($actualType, $activityData, $result);

		return $result;
	}

	/**
	 * The document of a node-action proxy, by the rules the document of an action is checked by. An action
	 * the portal does not know is left to the catalog check: it is the one that answers for the action
	 * itself, and a second refusal here would only say the same thing twice.
	 */
	private function validateProxyDocument(BaseSettingsExpressionDto $expression, Result $result): void
	{
		$description = $this->searcher->searchByCode((string)$expression->actionId);
		if ($description === null)
		{
			return;
		}

		$this->validateActionDocument(
			$expression->document,
			$description->getName(),
			$description->getNodeActionSettingsDto()?->handlesDocument ?? false,
			$result,
		);
	}

	private function validateHostMergedBaseSettingsType(string $actualType, array $activityData, Result $result): void
	{
		if ($actualType === '')
		{
			return;
		}

		$resolver = new TriggerUpgradeResolver();
		$upgrade = $resolver->resolveUpgradedType(
			(string)$this->activityType,
			$resolver->resolveNodeDocumentType(
				[
					$this->activityProperties['Document'] ?? null,
					$activityData['Properties']['Document'] ?? null,
				],
				$this->documentType ?? [],
			),
		);

		if (
			$resolver->isSameType($actualType, (string)$this->activityType)
			|| $resolver->isSameType($actualType, $upgrade['type'])
		)
		{
			return;
		}

		$result->addError(new Error(
			'Trigger type transition is not allowed',
			'TRIGGER_TYPE_TRANSITION_NOT_ALLOWED',
		));
	}

	/**
	 * Validate that the block type of a construction is allowed by the node's capability descriptor.
	 * Only condition/action/filter/output are validated; relations/group/storages are
	 * not yet active in the pipeline and are not checked here.
	 */
	private function validateConstructionBlockType(ConstructionDto $construction): Result
	{
		$nodeBlockType = $this->resolveNodeBlockType($construction->constructionType);
		if ($nodeBlockType === null)
		{
			// Unknown ConstructionType not in the validated set — skip
			return new Result();
		}

		$catalog = $this->getCatalog();
		if ($catalog === null)
		{
			// No catalog available — skip descriptor validation (graceful degradation)
			return new Result();
		}

		if (!$catalog->availableBlocks->isAvailable($nodeBlockType))
		{
			$result = new Result();
			$result->addError(new Error(
				Loc::getMessage('BIZPROCDESIGNER_COMMAND_VALIDATE_SINGLE_RULE_BLOCK_NOT_AVAILABLE', [
					'#BLOCK#' => $nodeBlockType->value,
				]) ?? sprintf('Block type "%s" is not available for this node.', $nodeBlockType->value),
				'ERR-BLOCK-NOT-AVAILABLE',
			));

			return $result;
		}

		return new Result();
	}

	/**
	 * Validate that an action in an ACTION construction is present in the node's capability catalog.
	 * Checks actual activityData.Type against declared actionId to prevent payload substitution.
	 *
	 * @param ActionExpressionDto $expression
	 * @param ConstructionType $constructionType The construction type; catalog check is only done for ACTION.
	 * @param ValidationResult $result
	 */
	private function validateActionInCatalog(
		ActionExpressionDto $expression,
		ConstructionType $constructionType,
		ValidationResult $result,
	): void
	{
		// Catalog actions check applies only to ACTION constructions, not FILTER.
		if ($constructionType !== ConstructionType::ACTION)
		{
			return;
		}

		$this->validateActionCatalogMembership(
			$expression->actionId,
			(string)($expression->activityData['Type'] ?? ''),
			$result,
		);

		$this->validateActionAreaObject($expression, $result);
	}

	/**
	 * Cross-check that area/object carried by the expression belong to the catalog entry of the
	 * resolved action. Area/object are navigation dimensions; the truth is the actionId, so an
	 * inconsistent pair is rejected. A flat/legacy payload without area/object is not blocked.
	 */
	private function validateActionAreaObject(ActionExpressionDto $expression, Result $result): void
	{
		if ($expression->area === null && $expression->object === null)
		{
			return;
		}

		$entry = $this->findCatalogEntry($expression->actionId);
		if ($entry === null)
		{
			return;
		}

		$areaOk = $expression->area === null
			|| in_array($expression->area, array_column($entry->areas ?? [], 'id'), true);
		$objectOk = $expression->object === null
			|| in_array($expression->object, array_column($entry->objects ?? [], 'id'), true);

		if (!$areaOk || !$objectOk)
		{
			$message = Loc::getMessage('BIZPROCDESIGNER_COMMAND_VALIDATE_SINGLE_RULE_ACTION_AREA_OBJECT_MISMATCH')
				?? 'Action area/object is not available for this node.';
			$result->addError(new Error($message, 'ERR-ACTION-AREA-OBJECT-MISMATCH'));
		}
	}

	private function findCatalogEntry(?string $actionId): ?NodeActionCatalogEntry
	{
		$catalog = $this->getCatalog();
		if ($catalog === null || $actionId === null || $actionId === '')
		{
			return null;
		}

		$normalized = $this->searcher->normalizeActivityCode($actionId);
		foreach ($catalog->actions as $action)
		{
			if (!($action instanceof NodeActionCatalogEntry))
			{
				continue;
			}

			if ($this->searcher->normalizeActivityCode($action->id) === $normalized)
			{
				return $action;
			}
		}

		return null;
	}

	/**
	 * Payload-guard (declared actionId vs actual activityData.Type) plus node catalog membership.
	 * Shared by ACTION constructions and node-action-backed BASE_SETTINGS constructions so the same
	 * substitution guard and catalog check apply regardless of how the node-action reached the node.
	 *
	 * @param string|null $declaredActionId Action id declared by the client.
	 * @param string $actualType activityData.Type carried by the actual payload.
	 * @param Result $result Accumulator for validation errors (ValidationResult or plain Result).
	 */
	private function validateActionCatalogMembership(
		?string $declaredActionId,
		string $actualType,
		Result $result,
	): void
	{
		$catalog = $this->getCatalog();
		if ($catalog === null)
		{
			return;
		}

		// Detect payload substitution — declared actionId vs actual activityData.Type.
		$actualActionId = $actualType !== '' ? $actualType : (string)$declaredActionId;

		$normalizedDeclared = $this->searcher->normalizeActivityCode((string)$declaredActionId);
		$normalizedActual = $this->searcher->normalizeActivityCode($actualActionId);

		if ($normalizedDeclared !== $normalizedActual)
		{
			$message = Loc::getMessage('BIZPROCDESIGNER_COMMAND_VALIDATE_SINGLE_RULE_PAYLOAD_TYPE_MISMATCH', [
				'#DECLARED#' => $declaredActionId,
				'#ACTUAL#' => $actualActionId,
			]) ?? sprintf(
				'Payload type "%s" does not match declared action "%s".',
				$actualActionId,
				(string)$declaredActionId,
			);
			$result->addError(new Error($message, 'ERR-ACTION-TYPE-MISMATCH'));

			return;
		}

		// Build the normalized hash-map once per command instance.
		if ($this->catalogActionIdMap === null)
		{
			$this->catalogActionIdMap = [];
			foreach ($catalog->actions as $action)
			{
				$id = $action instanceof NodeActionCatalogEntry
					? $action->id
					: (string)($action['id'] ?? '');
				if ($id !== '')
				{
					$this->catalogActionIdMap[$this->searcher->normalizeActivityCode($id)] = true;
				}
			}
		}

		if (!isset($this->catalogActionIdMap[$normalizedActual]))
		{
			$message = Loc::getMessage('BIZPROCDESIGNER_COMMAND_VALIDATE_SINGLE_RULE_ACTION_NOT_IN_CATALOG', [
				'#NAME#' => $actualActionId,
			]) ?? sprintf('Action "%s" is not available for this node.', $actualActionId);
			$result->addError(new Error($message, 'ERR-ACTION-NOT-IN-CATALOG'));
		}
	}

	private function getCatalog(): ?NodeCapabilityCatalog
	{
		if ($this->activityType === null)
		{
			return null;
		}

		if ($this->catalogCache === null)
		{
			$complexActivityService = Container::instance()->getComplexActivityService();
			// Same split as the two endpoints: the filter gate reads the class-declared type, the catalog is
			// built in the document context the node works in (for a trigger - the document of its event).
			$fixedDocumentType = $complexActivityService->getFixedDocumentTypeForNodeAction($this->activityType);
			$publishedDocumentType = $complexActivityService->getPublishedDocumentTypeForNode(
				$this->activityType,
				$this->activityProperties ?? [],
			);
			$documentType = $this->documentType ?? [];
			$filterAvailability = DesignerContainer::getNodeFilterAvailability();
			$relationsAvailable = Feature::instance()->areComplexNodeConnectionsAvailable();

			// Same document context as the capability catalog endpoint: an action locked in
			// this context is absent from the catalog and fails ERR-ACTION-NOT-IN-CATALOG.
			$catalog = Container::instance()->getCapabilityCatalogService()->getCatalogForNode(
				activityType: $this->activityType,
				filterAvailable: $filterAvailability->isRuntimeAvailable($fixedDocumentType, $documentType),
				relationsAvailable: $relationsAvailable,
				documentType: $filterAvailability->resolveEffectiveDocumentType($publishedDocumentType, $documentType),
				filterModuleSupported: $filterAvailability->supportsModule($fixedDocumentType, $documentType),
			);

			$this->catalogCache = $catalog ?? false;
		}

		return $this->catalogCache === false ? null : $this->catalogCache;
	}

	/**
	 * Map ConstructionType to NodeBlockType for descriptor validation.
	 * Returns null for types not in the validated set.
	 */
	private function resolveNodeBlockType(ConstructionType $constructionType): ?NodeBlockType
	{
		return match ($constructionType)
		{
			ConstructionType::IF_CONDITION,
			ConstructionType::AND_CONDITION,
			ConstructionType::OR_CONDITION => NodeBlockType::CONDITION,
			ConstructionType::ACTION => NodeBlockType::ACTION,
			ConstructionType::FILTER => NodeBlockType::FILTER,
			ConstructionType::OUTPUT => NodeBlockType::OUTPUT,
			// Security: validate base-settings availability against the node descriptor.
			// Blocks a direct payload that sends BASE_SETTINGS on a node where the block
			// is not declared as available (availableBlocks['base-settings'].available === false).
			ConstructionType::BASE_SETTINGS => NodeBlockType::BASE_SETTINGS,
			default => null,
		};
	}

	private function validateExpression(BaseExpressionDto $expression, ConstructionType $constructionType): ValidationResult
	{
		$result = $this->validationService->validate($expression);

		if ($expression instanceof ActionExpressionDto && $expression->actionId)
		{
			$this->validateAction($expression, $constructionType, $result);
		}

		return $result;
	}

	private function validateAction(
		ActionExpressionDto $expression,
		ConstructionType $constructionType,
		ValidationResult $result,
	): void
	{
		$description = $this->searcher->searchByCode($expression->actionId);
		if (!$description)
		{
			$message = Loc::getMessage('BIZPROCDESIGNER_COMMAND_VALIDATE_SINGLE_RULE_NO_ACTIVITY', [
				'#NAME#' => $expression->actionId,
			]);
			$result->addError(new Error($message));

			return;
		}

		$handlesDocument = $description->getNodeActionSettingsDto()?->handlesDocument ?? false;
		$this->validateActionDocument($expression->document, $description->getName(), $handlesDocument, $result);

		if ($this->activityType !== null)
		{
			$this->validateActionInCatalog($expression, $constructionType, $result);
		}
	}

	/**
	 * The document field of an action takes a value of any shape the author needs - a bare id, a constant, a
	 * variable, an output of another node - and what it names is only known when the process runs. So the
	 * check here is on the format alone, and refuses only what could never name a document
	 * ({@see ActionDocumentValue}). A value that turns out to name nothing at run time skips the node and
	 * says so in the journal, instead of silently working over the document of the process.
	 */
	private function validateActionDocument(
		?string $document,
		string $actionName,
		bool $handlesDocument,
		Result $result,
	): void
	{
		if (!ActionDocumentValue::isBound($document))
		{
			if ($handlesDocument)
			{
				$result->addError(new Error(
					(string)Loc::getMessage('BIZPROCDESIGNER_COMMAND_VALIDATE_SINGLE_RULE_NO_HANDLE_DOCUMENT', [
						'#NAME#' => $actionName,
					])
				));
			}

			return;
		}

		if (!ActionDocumentValue::isAcceptable($document))
		{
			$result->addError(new Error(
				(string)Loc::getMessage('BIZPROCDESIGNER_COMMAND_VALIDATE_SINGLE_RULE_BAD_DOCUMENT', [
					'#NAME#' => $actionName,
				])
			));
		}
	}
}
