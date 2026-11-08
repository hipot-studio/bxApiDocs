<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\Bizproc\Public\Activity\Interface\NodeFilterMetadataProvider;
use Bitrix\BizprocDesigner\Internal\Entity\ComplexBlockDetail;
use Bitrix\BizprocDesigner\Internal\Entity\ComplexNodeAction;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentComplexConstruction;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentComplexRule;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentComplexRules;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\GraphErrorCode;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Error\GraphError;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\RestrictedSettingRegistry;
use Bitrix\BizprocDesigner\Internal\Service\Activity\ActionDocumentValue;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\Result;
use CBPRuntime;

/**
 * Validates the nested rules projection (DTO-01) of a complex node against the block's own complex
 * detail (DTO-02) before the graph is materialised:
 *
 * - `action.activityCode` must be in the sub-action dictionary (reused from
 *   {@see ComplexBlockDetail::$nodeActions}, itself derived from the domain
 *   {@see \Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService}); switchnode carries an
 *   empty dictionary -> any `action` is rejected, only `condition`/`output` remain valid;
 * - condition types come from the closed allowlist {@see AgentComplexConstruction::TYPES};
 *   `codecondition` is rejected with an explicit error as a second contour (defense in depth);
 * - a `filter` construction is accepted only when the environment supports it
 *   ({@see ComplexBlockDetail::$filterSupported}) AND the backing activity implements
 *   {@see NodeFilterMetadataProvider} - otherwise an explicit error, not a silent degradation.
 *
 * A nested activity carries its settings as verbatim as the host node does, so the restricted-setting barrier
 * (ALG-04) reaches this level too: a name the nested activity declares restricted is held here to the graph the
 * server wrote exactly as {@see AgentBlockValidator} holds a top-level one.
 *
 * On success {@see self::getValidRules()} returns the {@see AgentComplexRules} projection.
 */
final class AgentComplexRulesValidator
{
	/** Condition kinds that must never be expressible; rejected explicitly (defense in depth). */
	private const CODE_CONDITION_TYPES = ['codecondition', 'code', 'php', 'condition:code'];

	/**
	 * Keys the designer itself writes into a sub-action's properties, so they are known names rather than a
	 * schema violation on the way back:
	 *
	 * - `EditorComment` - written on a manual save of the rule by the shared activity-settings save path
	 *   ({@see \Bitrix\BizprocDesigner\Public\Command\Activity\Settings\SaveCommandHandler}), the one
	 *   {@see \Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\SaveSingleRuleCommand} routes a
	 *   sub-action through. Not the rule engine: {@see \Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ConvertRuleCommand}
	 *   does the opposite and strips the key when it merges base-settings into the host;
	 * - `TargetFilterId` - the upstream filter an action reads its document from, stamped by `ConvertRuleCommand`;
	 * - `auxPort` - unconfirmed. Its only write (`ConvertRuleCommand`) lands in the properties of the
	 *   materialised child, while the way back reads the canonical `Rules` copy and never the derived children,
	 *   so no observed path delivers this key to the check. Kept rather than dropped: the write side is not
	 *   proven exhaustive, and removing it could only turn a saved node into a refusal.
	 *
	 * They live here and not in {@see ComplexNodeAction} because they are the same for every sub-action class -
	 * a field per entry would only copy one constant into dozens of entries. Being known does not put them back
	 * into the schema: the node's property set, and with it the template revision hash, stays where it was.
	 */
	private const SERVICE_SETTING_NAMES = ['EditorComment', 'auxPort', 'TargetFilterId'];

	// `D`: without it `$` also matches before a trailing newline, so "i0\n" would pass as a port id.
	private const INPUT_PORT_PATTERN = '/^i\d+$/D';
	private const OUTPUT_PORT_PATTERN = '/^o\d+$/D';

	private ?AgentComplexRules $validRules = null;

	private readonly ?ValidationContext $context;
	private readonly RestrictedSettingRegistry $restrictedSettings;

	/**
	 * The document type the host block is judged in; the nested level is judged in the same one. Resolved per run
	 * from the parameter or, failing that, from the context - never left unresolved while a context is at hand.
	 */
	private ?DocumentDescription $documentType = null;

	/** The host node's type, for the baseline the condition barrier is lifted against; null leaves it closed. */
	private ?string $blockType = null;

	/**
	 * The document type the sub-steps of this run are described for - the host node's effective one, resolved per
	 * run from its complex detail and the type of the edited template. It is a part of the option-list passthrough
	 * key ({@see self::validateNestedSettingNames()}), so it is resolved through the very method the collecting
	 * side of that key uses ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\ValidationContextFactory::nestedDocumentTypeOf()}),
	 * and not by a formula of its own.
	 *
	 * That method is not the one the editor asks, though. Both ends here read the fixed type of the node action
	 * ({@see \Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService::getFixedDocumentTypeForNodeAction()},
	 * through {@see ComplexBlockDetail::$fixedDocumentType}), while the editor reads
	 * `getPublishedDocumentTypeForNode($type, $properties)`. The two answers diverge on exactly six shipped
	 * triggers - `crm{company,contact,deal,lead,quote,smartinvoice}manualstarttrigger` - and none of them can be a
	 * host node here: a detail is built only for a NODE_TYPE of `complex`, which none of the six declares. So the
	 * key stays consistent with the side that collects it; the divergence is named rather than relied upon.
	 */
	private ?array $nestedDocumentType = null;

	public function __construct(
		?ValidationContext $context = null,
		?RestrictedSettingRegistry $restrictedSettings = null,
	) {
		$this->context = $context;
		$this->restrictedSettings = $restrictedSettings ?? new RestrictedSettingRegistry();
	}

	/**
	 * @param DocumentDescription|null $documentType the type the restricted names are asked of the nested activity
	 *        for. An activity declares them per document type, so without one that barrier cannot be asked at all -
	 *        which is why omitting it does not switch the barrier off: the type is then taken from the context, and
	 *        it is the very one the baseline of that context was fingerprinted for. Both unset means a validator
	 *        assembled by hand outside a request - with no baseline to judge against either - and it keeps the
	 *        verdict it had before.
	 * @param string|null $blockType the host node's type. Only the published-baseline relaxation of the condition
	 *        barrier needs it, and without it that relaxation simply does not apply.
	 */
	public function validate(
		mixed $rules,
		ComplexBlockDetail $complexDetail,
		string $path = '',
		?DocumentDescription $documentType = null,
		?string $blockType = null,
	): Result
	{
		$this->validRules = null;
		$this->documentType = $documentType ?? $this->context?->documentType;
		$this->blockType = $blockType;
		$this->nestedDocumentType = Container::getNodeFilterAvailability()->resolveEffectiveDocumentType(
			$complexDetail->fixedDocumentType,
			$this->documentType?->toBizprocComplexType() ?? [],
		);

		$result = new Result();

		if (!is_array($rules) || $rules === [])
		{
			return $result->addError(GraphError::at(
				$path,
				"{$path} should be a non-empty map of input ports to rules",
				GraphErrorCode::GraphElementInvalid,
			));
		}

		$allowedActivityCodes = $this->collectAllowedActivityCodes($complexDetail);

		$portRules = [];
		foreach ($rules as $portId => $portRuleList)
		{
			$portPath = "{$path}.{$portId}";

			if (!is_string($portId) || preg_match(self::INPUT_PORT_PATTERN, $portId) !== 1)
			{
				$result->addError(GraphError::at(
					$portPath,
					"{$portPath} is not a valid input port id (expected 'iN')",
					GraphErrorCode::PortInvalid,
				));

				continue;
			}

			if (!is_array($portRuleList) || $portRuleList === [])
			{
				$result->addError(GraphError::at(
					$portPath,
					"{$portPath} should be a non-empty list of rules",
					GraphErrorCode::GraphElementInvalid,
				));

				continue;
			}

			$rulesForPort = [];
			foreach (array_values($portRuleList) as $ruleKey => $rawRule)
			{
				$rule = $this->validateRule(
					$rawRule,
					"{$portPath}.{$ruleKey}",
					$complexDetail,
					$allowedActivityCodes,
					$result,
				);
				if ($rule !== null)
				{
					$rulesForPort[] = $rule;
				}
			}

			if ($rulesForPort !== [])
			{
				$portRules[$portId] = $rulesForPort;
			}
		}

		if ($result->isSuccess())
		{
			$this->validRules = new AgentComplexRules($portRules);
		}

		return $result;
	}

	public function getValidRules(): ?AgentComplexRules
	{
		return $this->validRules;
	}

	private function validateRule(
		mixed $rawRule,
		string $path,
		ComplexBlockDetail $complexDetail,
		array $allowedActivityCodes,
		Result $result,
	): ?AgentComplexRule
	{
		if (!is_array($rawRule))
		{
			$result->addError(GraphError::at($path, "{$path} should be object", GraphErrorCode::GraphElementInvalid));

			return null;
		}

		$id = $rawRule['id'] ?? null;
		if (!is_string($id) || $id === '')
		{
			$idPath = "{$path}.id";
			$result->addError(GraphError::at(
				$idPath,
				"{$idPath} should be not empty string",
				GraphErrorCode::GraphElementInvalid,
			));
		}

		$constructions = $rawRule['constructions'] ?? null;
		if (!is_array($constructions) || $constructions === [])
		{
			$constructionsPath = "{$path}.constructions";
			$result->addError(GraphError::at(
				$constructionsPath,
				"{$constructionsPath} should be a non-empty list",
				GraphErrorCode::GraphElementInvalid,
			));

			return null;
		}

		$validConstructions = [];
		foreach (array_values($constructions) as $key => $rawConstruction)
		{
			$construction = $this->validateConstruction(
				$rawConstruction,
				"{$path}.constructions.{$key}",
				$complexDetail,
				$allowedActivityCodes,
				$result,
			);
			if ($construction !== null)
			{
				$validConstructions[] = $construction;
			}
		}

		if (!is_string($id) || $id === '' || $validConstructions === [])
		{
			return null;
		}

		return new AgentComplexRule($id, $validConstructions);
	}

	private function validateConstruction(
		mixed $rawConstruction,
		string $path,
		ComplexBlockDetail $complexDetail,
		array $allowedActivityCodes,
		Result $result,
	): ?AgentComplexConstruction
	{
		if (!is_array($rawConstruction))
		{
			$result->addError(GraphError::at($path, "{$path} should be object", GraphErrorCode::GraphElementInvalid));

			return null;
		}

		$typePath = "{$path}.type";

		$type = $rawConstruction['type'] ?? null;
		if (!is_string($type) || $type === '')
		{
			$result->addError(GraphError::at(
				$typePath,
				"{$typePath} should be not empty string",
				GraphErrorCode::GraphElementInvalid,
			));

			return null;
		}

		if (in_array(mb_strtolower($type), self::CODE_CONDITION_TYPES, true))
		{
			$result->addError(GraphError::at(
				$typePath,
				"{$typePath} '{$type}': code conditions are not allowed in complex node rules",
				GraphErrorCode::GraphElementInvalid,
			));

			return null;
		}

		if (!in_array($type, AgentComplexConstruction::TYPES, true))
		{
			$result->addError(GraphError::at(
				$typePath,
				"{$typePath} '{$type}' is not one of: " . implode(', ', AgentComplexConstruction::TYPES),
				GraphErrorCode::GraphElementInvalid,
			));

			return null;
		}

		$expressionPath = "{$path}.{$type}";

		$expression = $rawConstruction[$type] ?? null;
		if (!is_array($expression))
		{
			$result->addError(GraphError::at(
				$expressionPath,
				"{$expressionPath} should be object",
				GraphErrorCode::GraphElementInvalid,
			));

			return null;
		}

		$expressionResult = match ($type)
		{
			AgentComplexConstruction::TYPE_CONDITION =>
				$this->validateCondition($expression, $expressionPath, $complexDetail),
			AgentComplexConstruction::TYPE_ACTION =>
				$this->validateAction($expression, $expressionPath, $complexDetail, $allowedActivityCodes),
			AgentComplexConstruction::TYPE_FILTER => $this->validateFilter($expression, $expressionPath, $complexDetail),
			AgentComplexConstruction::TYPE_OUTPUT => $this->validateOutput($expression, $expressionPath),
		};

		$result->addErrors($expressionResult->getErrors());
		if (!$expressionResult->isSuccess())
		{
			return null;
		}

		return new AgentComplexConstruction($type, $expression);
	}

	/**
	 * Condition contract (DTO-03): a field-based comparison projected from `mixedcondition`. Field codes
	 * are stable (object + fieldId), not localized labels; `value` is carried verbatim (bizproc
	 * expressions `{=...}`/`{{=...}}` included), typed-value resolution is left to the converter.
	 *
	 * Availability is judged first, exactly as {@see self::validateFilter()} judges its own: a node whose block
	 * composition takes no condition ({@see ComplexBlockDetail::$conditionSupported}) is the node the save path
	 * refuses one on ({@see \Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ValidateSingleRuleCommand}),
	 * and accepting it here would hand the user a draft that cannot be saved with no reason named anywhere.
	 * The relaxation is the one the EXCLUDED barrier uses: a node type the published version is built of keeps
	 * its way back, so a portal that switched the block off does not strip an already published condition.
	 */
	private function validateCondition(array $condition, string $path, ComplexBlockDetail $complexDetail): Result
	{
		$result = new Result();

		if (!$complexDetail->conditionSupported && !$this->isBaselineBlockType())
		{
			return $result->addError(GraphError::at(
				$path,
				"{$path}: condition rules are not supported by this node",
				GraphErrorCode::GraphElementNotAllowed,
			));
		}

		$fieldPath = "{$path}.field";

		$field = $condition['field'] ?? null;
		if (!is_array($field))
		{
			$result->addError(GraphError::at(
				$fieldPath,
				"{$fieldPath} should be object",
				GraphErrorCode::GraphElementInvalid,
			));
		}
		else
		{
			$objectPath = "{$fieldPath}.object";
			$object = $field['object'] ?? null;
			if (!is_string($object) || $object === '')
			{
				$result->addError(GraphError::at(
					$objectPath,
					"{$objectPath} should be not empty string",
					GraphErrorCode::GraphElementInvalid,
				));
			}

			$fieldIdPath = "{$fieldPath}.fieldId";
			$fieldId = $field['fieldId'] ?? null;
			if (!is_string($fieldId) || $fieldId === '')
			{
				$result->addError(GraphError::at(
					$fieldIdPath,
					"{$fieldIdPath} should be not empty string",
					GraphErrorCode::GraphElementInvalid,
				));
			}
		}

		$operatorPath = "{$path}.operator";
		$operator = $condition['operator'] ?? null;
		if (!is_string($operator) || $operator === '')
		{
			$result->addError(GraphError::at(
				$operatorPath,
				"{$operatorPath} should be not empty string",
				GraphErrorCode::GraphElementInvalid,
			));
		}

		$joinerPath = "{$path}.joiner";
		$joiner = $condition['joiner'] ?? null;
		if ($joiner !== null && !in_array($joiner, ['AND', 'OR'], true))
		{
			$result->addError(GraphError::at(
				$joinerPath,
				"{$joinerPath} should be 'AND' or 'OR'",
				GraphErrorCode::GraphElementInvalid,
			));
		}

		return $result;
	}

	/** Whether the published version is built of this node's type - the set the EXCLUDED barrier is lifted against. */
	private function isBaselineBlockType(): bool
	{
		return $this->blockType !== null
			&& isset($this->context->baselineBlockTypes[mb_strtolower($this->blockType)])
		;
	}

	private function validateAction(
		array $action,
		string $path,
		ComplexBlockDetail $complexDetail,
		array $allowedActivityCodes,
	): Result
	{
		$result = new Result();

		$activityCodePath = "{$path}.activityCode";

		$activityCode = $action['activityCode'] ?? null;
		if (!is_string($activityCode) || $activityCode === '')
		{
			return $result->addError(GraphError::at(
				$activityCodePath,
				"{$activityCodePath} should be not empty string",
				GraphErrorCode::GraphElementInvalid,
			));
		}

		if ($allowedActivityCodes === [])
		{
			// Router nodes (switchnode) expose an empty dictionary -> sub-actions are not allowed.
			return $result->addError(GraphError::at(
				$path,
				"{$path}: this complex node does not allow sub-actions (only condition/output)",
				GraphErrorCode::GraphElementNotAllowed,
			));
		}

		if (!in_array(mb_strtolower($activityCode), $allowedActivityCodes, true))
		{
			return $result->addError(GraphError::at(
				$activityCodePath,
				"{$activityCodePath} '{$activityCode}' is not in the allowed sub-action dictionary",
				GraphErrorCode::SubActionUnknown,
			));
		}

		$result->addErrors($this->validateNestedSettingsShape($action, $path)->getErrors());
		$result->addErrors($this->authorizeRestrictedSettings($action, $activityCode, $path)->getErrors());

		$nodeAction = $this->findNodeActionByCode($complexDetail, $activityCode);
		if ($nodeAction !== null)
		{
			$result->addErrors($this->validateNestedSettingNames($action, $path, $nodeAction)->getErrors());
		}

		$result->addErrors($this->validateActionDocument($action, $path, $nodeAction)->getErrors());

		return $result;
	}

	/**
	 * `action.document` binds the sub-action to a document. What it may hold is decided by
	 * {@see ActionDocumentValue}, the very rule the settings panel applies
	 * ({@see \Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ValidateSingleRuleCommand}): a bare id,
	 * an expression on a constant, a variable or an output of another node - anything but what could never
	 * name a document, which is a non-string value or the `"Array"` a cast collection leaves behind.
	 *
	 * This contour used to demand an expression and refuse `"42"`, so one and the same template was valid in
	 * the panel and invalid over REST. Requiredness stays as it was: a document-handling sub-action
	 * ({@see ComplexNodeAction::$handlesDocument}) must bind one.
	 *
	 * Reachability of the referenced source from this point of the graph is deliberately NOT checked - that stays with
	 * materialisation ({@see \Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ConvertRuleCommand}), the agent
	 * validator has no graph topology.
	 */
	private function validateActionDocument(array $action, string $path, ?ComplexNodeAction $nodeAction): Result
	{
		$result = new Result();

		$documentPath = "{$path}.document";
		$document = $action['document'] ?? null;

		if (ActionDocumentValue::isBound($document))
		{
			if (!ActionDocumentValue::isAcceptable($document))
			{
				$result->addError(GraphError::at(
					$documentPath,
					"{$documentPath} must be a document id or a bizproc expression naming one"
					. " (e.g. '42', '{=Constant:X}', '{=<triggerId>:ReturnDocument}'), not a document-type array",
					GraphErrorCode::GraphElementInvalid,
				));
			}

			return $result;
		}

		if ($nodeAction !== null && $nodeAction->handlesDocument)
		{
			$result->addError(GraphError::at(
				$documentPath,
				"{$documentPath} is required for this sub-action (it handles the document): give it a document id"
				. " or a bizproc expression naming one",
				GraphErrorCode::GraphElementInvalid,
			));
		}

		return $result;
	}

	private function validateFilter(array $filter, string $path, ComplexBlockDetail $complexDetail): Result
	{
		$result = new Result();

		if (!$complexDetail->filterSupported)
		{
			return $result->addError(GraphError::at(
				$path,
				"{$path}: node filter is not supported in this environment",
				GraphErrorCode::GraphElementNotAllowed,
			));
		}

		$activityCodePath = "{$path}.activityCode";

		$activityCode = $filter['activityCode'] ?? null;
		if (!is_string($activityCode) || $activityCode === '')
		{
			return $result->addError(GraphError::at(
				$activityCodePath,
				"{$activityCodePath} should be not empty string",
				GraphErrorCode::GraphElementInvalid,
			));
		}

		if (!self::isNodeFilterBackingActivity($activityCode))
		{
			return $result->addError(GraphError::at(
				$activityCodePath,
				"{$activityCodePath} '{$activityCode}' is not a node-filter backing activity",
				GraphErrorCode::SubActionUnknown,
			));
		}

		$result->addErrors($this->validateNestedSettingsShape($filter, $path)->getErrors());
		$result->addErrors($this->authorizeRestrictedSettings($filter, $activityCode, $path)->getErrors());

		return $result;
	}

	/**
	 * The nested half of ALG-04, kept identical to {@see AgentBlockValidator::authorizeRestrictedSettings()} down to
	 * the fingerprint and the wording: a setting the nested activity declares restricted carries an executable
	 * payload, so the agent may only re-send what the server wrote. The names are asked of the activity the
	 * construction names, never of the host node - a sub-action is a different class with restrictions of its own.
	 *
	 * What it is matched against is what the context assembled from the graphs the server wrote - the published
	 * version and the graph the agent was handed - sub-steps of both included
	 * ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\ValidationContextFactory}), so a nested
	 * value is inherited when the very same (name, value) pair stands anywhere in one of them - at this level or the
	 * top one. Collecting the top level alone would refuse a sub-step such a graph already carries, breaking the
	 * round-trip of an untouched template rather than the barrier.
	 */
	private function authorizeRestrictedSettings(array $expression, string $activityCode, string $path): Result
	{
		$result = new Result();
		$settings = $expression['settings'] ?? null;
		// Asked before the registry is: resolving restricted names loads the activity file, and a construction
		// carrying no settings has nothing for them to judge anyway.
		if ($this->documentType === null || !is_array($settings) || $settings === [])
		{
			return $result;
		}

		$restrictedNames = $this->restrictedSettings->namesFor($activityCode, $this->documentType);
		if ($restrictedNames === [])
		{
			return $result;
		}

		foreach ($settings as $name => $value)
		{
			if (!is_string($name) || !in_array(mb_strtolower($name), $restrictedNames, true))
			{
				continue;
			}

			if (RestrictedSettingRegistry::carriesNoPayload($value))
			{
				continue;
			}

			// Fail-closed exactly as the top-level half is: no context is no graph the server wrote.
			if (!$this->context?->carriesRestrictedSettingValue($name, $value))
			{
				$result->addError(GraphError::at(
					"{$path}.settings.{$name}",
					"{$path}.settings.{$name} must match the published template baseline"
					. ' (this setting carries an executable payload and can only be passed through unchanged)',
					GraphErrorCode::ImmutableNodePolicyViolation,
				));
			}
		}

		return $result;
	}

	/**
	 * A sub-action's / filter's `settings` is the child activity's `Properties`, so it must be a settings map
	 * ({name: value}), never a list. The reverse converter only ever emits a map or an empty `[]` (see
	 * {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAssistantWorkflowTemplateConverterService::mapActionExpressionToProjection}),
	 * so a non-empty list can only come from an agent copying the top-level `settings` shape (a `[{name, value}]`
	 * list, which is correct there but not here). Left unchecked it would be saved under the numeric key `0` and
	 * leave the activity property empty - a silent loss. The empty `[]` stays valid to keep the round-trip intact.
	 */
	private function validateNestedSettingsShape(array $expression, string $path): Result
	{
		$result = new Result();

		$settings = $expression['settings'] ?? null;
		if (is_array($settings) && $settings !== [] && array_is_list($settings))
		{
			$settingsPath = "{$path}.settings";
			$result->addError(GraphError::at(
				$settingsPath,
				"{$settingsPath} should be a settings map (an object keyed by setting name), not a list",
				GraphErrorCode::SettingValueInvalid,
			));
		}

		return $result;
	}

	/**
	 * B1+ name and value cross-check of a sub-action's settings, run through the very core an ordinary block
	 * uses ({@see AgentSettingNameValidator::validateAgainstSchema()}), so the same name gets the same verdict
	 * at both levels. Beside the typed schema (DTO-04) a name is allowed when the sub-action's description
	 * could not express it ({@see ComplexNodeAction::$skippedSettings}) or when it is a service key of this
	 * level ({@see self::SERVICE_SETTING_NAMES}). A named setting is then held to the option set the schema
	 * published to the agent - only that branch of the value check applies here
	 * ({@see AgentSettingValueValidator::validateAgainstOptions()}): requiredness is not enforced because the
	 * draft check also runs on a half-built graph, and the value is never replaced by a normalised one, which
	 * would move the node's property set and thus the template revision hash.
	 *
	 * An empty schema (an HTML-only sub-action or a schema unavailable in the environment) disables the check,
	 * otherwise every setting would be a false "unknown". List-shaped and empty settings are left to
	 * {@see self::validateNestedSettingsShape()}; a map that only partly looks like a list still lands here, and
	 * for its non-string keys the core's own diagnosis is what the agent gets.
	 *
	 * The passthrough is keyed at this level by what the schema is actually built from: the sub-action's activity
	 * code and the EFFECTIVE document type of the host node, and no preset
	 * ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\ComplexBlockDetailFactory::describeSubAction()}).
	 * The preset is absent rather than omitted: what {@see ComplexNodeAction::$presetId} holds is the dictionary's
	 * own variant of the palette entry, not a description context, and the projection a sub-step is written as
	 * carries no preset at all. The document type, by contrast, has to be named, and naming the type of the whole
	 * request would not do: a complex activity may fix a document type of its own, one template may hold nodes of
	 * two such activities, and the same sub-step may sit in both - a deal node and a lead node offering the same
	 * sub-step under two different option lists. Without that part of the key a status the deal node published
	 * would unlock it in the lead node, where the portal never offered it. The baseline is collected under the very
	 * same key ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\ValidationContextFactory}).
	 */
	private function validateNestedSettingNames(array $action, string $path, ComplexNodeAction $nodeAction): Result
	{
		$result = new Result();

		if ($nodeAction->settingsSchema->count() === 0)
		{
			return $result;
		}

		$settings = $action['settings'] ?? null;
		if (!is_array($settings) || $settings === [] || array_is_list($settings))
		{
			return $result;
		}

		$allowedNames = array_merge($nodeAction->skippedSettings, self::SERVICE_SETTING_NAMES);
		$nameValidator = new AgentSettingNameValidator();

		foreach ($settings as $settingName => $settingValue)
		{
			$settingPath = "{$path}.settings.{$settingName}";

			$nameResult = $nameValidator->validateAgainstSchema(
				$settingName,
				$settingValue,
				$settingPath,
				$nodeAction->settingsSchema,
				$allowedNames,
			);
			if (!$nameResult->isSuccess())
			{
				// Only a well-formed name that no schema knows is "not a known setting". A numeric or empty key
				// is a settings map that is really a list, and the core already says exactly that - restating it
				// as an unknown name would send the agent looking for a setting it never named.
				$isSettingName = is_string($settingName) && $settingName !== '';

				$result->addErrors(
					$isSettingName
						? [GraphError::at(
							$settingPath,
							"{$settingPath} is not a known setting of this sub-action",
							GraphErrorCode::SettingNameUnknown,
						)]
						: $nameResult->getErrors()
				);

				continue;
			}

			$setting = $nameValidator->getSetting();
			if ($setting !== null)
			{
				$valueResult = AgentSettingValueValidator::validateAgainstOptions(
					$settingValue,
					$setting,
					$settingPath,
					$this->context,
					$nodeAction->activityCode,
					// Named rather than left to the default: the empty preset is the answer here, not an omission.
					presetId: null,
					documentType: $this->nestedDocumentType,
				);
				$result->addErrors($valueResult->getErrors());
			}
		}

		return $result;
	}

	private function findNodeActionByCode(ComplexBlockDetail $complexDetail, string $activityCode): ?ComplexNodeAction
	{
		$needle = mb_strtolower($activityCode);
		foreach ($complexDetail->nodeActions as $nodeAction)
		{
			/** @var ComplexNodeAction $nodeAction */
			if (mb_strtolower($nodeAction->activityCode) === $needle)
			{
				return $nodeAction;
			}
		}

		return null;
	}

	private function validateOutput(array $output, string $path): Result
	{
		$result = new Result();

		$portIdPath = "{$path}.portId";
		$portId = $output['portId'] ?? null;
		if (!is_string($portId) || preg_match(self::OUTPUT_PORT_PATTERN, $portId) !== 1)
		{
			$result->addError(GraphError::at(
				$portIdPath,
				"{$portIdPath} is not a valid output port id (expected 'oN')",
				GraphErrorCode::PortInvalid,
			));
		}

		$titlePath = "{$path}.title";
		$title = $output['title'] ?? null;
		if (!is_string($title) || $title === '')
		{
			$result->addError(GraphError::at(
				$titlePath,
				"{$titlePath} should be not empty string",
				GraphErrorCode::GraphElementInvalid,
			));
		}

		return $result;
	}

	/**
	 * @return list<string> lowercased allowed sub-action activity codes taken from the block's complex
	 *         detail (DTO-02) - the same dictionary the manual editor and the domain use, not a parallel
	 *         AI-side list. Empty for switchnode / router nodes.
	 */
	private function collectAllowedActivityCodes(ComplexBlockDetail $complexDetail): array
	{
		$codes = [];
		foreach ($complexDetail->nodeActions as $nodeAction)
		{
			/** @var ComplexNodeAction $nodeAction */
			$codes[] = mb_strtolower($nodeAction->activityCode);
		}

		return $codes;
	}

	/**
	 * Mirrors {@see \Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ConvertRuleCommand}: the
	 * single source of truth for "is a filter backing activity" is the {@see NodeFilterMetadataProvider}
	 * interface. This is the runtime capability check, not the sub-action dictionary.
	 */
	private static function isNodeFilterBackingActivity(string $activityType): bool
	{
		if ($activityType === '')
		{
			return false;
		}

		if (!CBPRuntime::getRuntime()->includeActivityFile($activityType))
		{
			return false;
		}

		return is_subclass_of('CBP' . $activityType, NodeFilterMetadataProvider::class, true);
	}
}
