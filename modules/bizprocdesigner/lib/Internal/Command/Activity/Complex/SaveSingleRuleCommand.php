<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Command\Activity\Complex;

use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\Activity\ReturnPropertiesResolver;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\PortRuleDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ActionExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\BaseSettingsExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConditionExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Enum\ConstructionType;
use Bitrix\BizprocDesigner\Internal\Command\AbstractCommand;

use Bitrix\BizprocDesigner\Internal\Service\Activity\ReadableExpressionContextFactory;
use Bitrix\BizprocDesigner\Internal\Trait\ActivitySettingsDecoder;
use Bitrix\BizprocDesigner\Internal\Trait\ConditionValueResolver;
use Bitrix\BizprocDesigner\Internal\Trait\NodeActionResolver;
use Bitrix\BizprocDesigner\Public\Command\Activity;
use Bitrix\BizprocDesigner\Public\Service\Activity\TriggerUpgradeResolver;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

class SaveSingleRuleCommand extends AbstractCommand
{
	use ActivitySettingsDecoder;
	use ConditionValueResolver;
	use NodeActionResolver;

	private ?ReadableExpressionContextFactory $readableExpressionContextFactory = null;

	/**
	 * @param array $templateSlice Scheme slice used to build the action property map, with the host node first.
	 */
	public function __construct(
		public readonly PortRuleDto $portRuleDto,
		public array $documentType,
		public readonly array $templateSlice = [],
	)
	{
	}

	protected function execute(): Result
	{
		$resultPortRule = clone $this->portRuleDto;

		$result = $this->processActionExpressions($resultPortRule);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$result = $this->processBaseSettingsExpressions($resultPortRule);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$result = $this->processConditionExpressions($resultPortRule);
		if (!$result->isSuccess())
		{
			return $result;
		}

		return new SaveSingleRuleCommandResult($resultPortRule);
	}

	private function processActionExpressions(PortRuleDto $resultPortRule): Result
	{
		foreach ($this->extractActionExpressionList($resultPortRule) as $addressedExpression)
		{
			$result = $this->normalizeActionExpression($addressedExpression['expression']);
			if (!$result->isSuccess())
			{
				return $this->addressFailure($result, $addressedExpression['constructionId']);
			}
		}

		return new Result();
	}

	/**
	 * Normalise one ACTION or FILTER construction: rawActivityData -> activityData, by the route the activity
	 * of the construction takes. The address of a failure belongs to the traversal above, so neither this
	 * step nor the routes it takes know which construction they are working on.
	 */
	private function normalizeActionExpression(ActionExpressionDto $actionExpression): Result
	{
		if (empty($actionExpression->rawActivityData))
		{
			$result = $this->revalidateNormalizedNodeAction($actionExpression->activityData);
			if (!$result->isSuccess())
			{
				return $result;
			}

			$this->refreshActionReturnProperties($actionExpression, true);

			return new Result();
		}

		$documentModuleResult = $this->loadActionDocumentModule($actionExpression);
		if (!$documentModuleResult->isSuccess())
		{
			return $documentModuleResult;
		}

		// Node-action-backed ACTION: internalise field values through the backing node-action's
		// getPropertiesMap (FieldType::extractValue) instead of getActivitySettings, mirroring the
		// BASE_SETTINGS path. This targets legacy dual activities (robot + node-action, e.g.
		// CBPIMNotifyActivity): as an ACTION they render the unified getPropertiesMap form
		// (FieldName message_user_from), but getActivitySettings() routes through their legacy
		// getPropertiesDialogValues, which reads legacy dialog keys (from_user_id) and never sees
		// those FieldNames - so every value is dropped and the raw printable string reaches the USER
		// validator. Legacy-dialog node-actions (CBPSetFieldActivity, CBPCrmCreateToDoActivity) do
		// not implement IBPConfigurableActivity, so internalizeNodeActionProperties() resolves an
		// empty propertiesMap and returns null -> the getActivitySettings() path below is unchanged.
		$nodeActionResult = $this->extractNodeActionAction($actionExpression);
		if ($nodeActionResult !== null)
		{
			if ($nodeActionResult->isSuccess())
			{
				$this->refreshActionReturnProperties($actionExpression);
			}

			return $nodeActionResult;
		}

		$saveActivityResult = $this->getActivitySettings($actionExpression);
		if (
			!$saveActivityResult instanceof Activity\Settings\SaveCommandResult
			|| !$saveActivityResult->isSuccess()
		)
		{
			return $saveActivityResult;
		}

		$actionExpression->rawActivityData = null;
		$activityData = $saveActivityResult->getSettings()?->toArray() ?? [];
		if (!empty($activityData))
		{
			$activityData['Document'] = $actionExpression->document;
		}

		$actionExpression->activityData = $activityData;
		$this->refreshActionReturnProperties($actionExpression);

		return new Result();
	}

	/**
	 * The expressions to normalise, each next to the id of the construction carrying it: a failure of the
	 * traversal is answered with the address of that construction.
	 *
	 * @param PortRuleDto $portRuleDto
	 * @return list<array{constructionId: string, expression: ActionExpressionDto}>
	 */
	private function extractActionExpressionList(PortRuleDto $portRuleDto): array
	{
		$actionExpressionList = [];

		$rules = $portRuleDto->rules;
		foreach ($rules as $rule)
		{
			foreach ($rule->constructions as $construction)
			{
				if (
					$construction->constructionType !== ConstructionType::ACTION
					&& $construction->constructionType !== ConstructionType::FILTER
				)
				{
					continue;
				}

				$expression = $construction->expression;

				if (!$expression instanceof ActionExpressionDto)
				{
					continue;
				}

				$actionExpressionList[] = [
					'constructionId' => $construction->id,
					'expression' => $expression,
				];
			}
		}

		return $actionExpressionList;
	}

	/**
	 * Normalise a node-action-backed ACTION construction: rawActivityData → activityData, internalising
	 * field values through the backing node-action's getPropertiesMap (see internalizeNodeActionProperties).
	 * Only node-action backing types are considered; among those, non-unified activities (legacy dialog,
	 * empty propertiesMap) fall back to the shared getActivitySettings() pipeline. Returns:
	 *   - null           not a unified node-action ACTION → caller falls back to getActivitySettings
	 *                    (modern / legacy-dialog path unchanged);
	 *   - Result(errors) extraction/validation failed;
	 *   - Result(ok)     activityData populated, rawActivityData cleared.
	 */
	private function extractNodeActionAction(ActionExpressionDto $actionExpression): ?Result
	{
		$rawActivityData = $actionExpression->rawActivityData;
		$activityType = (string)($rawActivityData['activityType'] ?? '');

		if (!$this->isNodeActionBackingType($activityType))
		{
			return null;
		}

		$internalizeResult = $this->internalizeNodeActionProperties($activityType, $rawActivityData);
		if ($internalizeResult === null)
		{
			return null;
		}

		if (!$internalizeResult->isSuccess())
		{
			return $internalizeResult;
		}

		[
			'properties' => $properties,
			'activated' => $isActivated,
			'title' => $title,
		] = $internalizeResult->getData();

		$actionExpression->rawActivityData = null;
		$actionExpression->activityData = [
			'Name' => (string)($rawActivityData['id'] ?? ''),
			'Type' => $activityType,
			'Activated' => $isActivated ? 'Y' : 'N',
			'Properties' => array_merge($properties, ['Title' => $title]),
			'Document' => $actionExpression->document,
		];

		return new Result();
	}

	private function loadActionDocumentModule(ActionExpressionDto $actionExpression): Result
	{
		$documentTypeResult = $this->extractDocumentType($actionExpression->rawActivityData);
		if (!$documentTypeResult->isSuccess())
		{
			return $documentTypeResult;
		}

		$documentType = $documentTypeResult->getData()['documentType'] ?? null;
		if ($documentType === null)
		{
			return new Result();
		}

		$moduleId = (string)($documentType[0] ?? '');
		if ($moduleId === '' || !Loader::includeModule($moduleId))
		{
			return (new Result())->addError(ErrorMessage::ACCESS_DENIED->getCodedError());
		}

		$actionExpression->rawActivityData = $this->replaceNestedDocumentType(
			$actionExpression->rawActivityData,
			$documentType,
		);

		return new Result();
	}

	private function refreshActionReturnProperties(
		ActionExpressionDto $actionExpression,
		bool $preserveExistingOnEmpty = false,
	): void
	{
		$activityData = $actionExpression->activityData;
		if (empty($activityData) || empty($activityData['Type']))
		{
			return;
		}

		\CBPRuntime::getRuntime()->includeActivityFile($activityData['Type']);
		$returnProperties = ReturnPropertiesResolver::resolve($activityData);
		if (
			$preserveExistingOnEmpty
			&& $returnProperties === []
			&& !empty($activityData['ReturnProperties'])
		)
		{
			return;
		}

		$activityData['ReturnProperties'] = $returnProperties;
		$actionExpression->activityData = $activityData;
	}

	/**
	 * Runs the shared activity save pipeline for one expression. A success always carries
	 * Activity\Settings\SaveCommandResult, so the callers narrow the result to it before they read the
	 * settings; every other outcome - the rejected nested document type included - is a failed Result they
	 * propagate as is.
	 */
	private function getActivitySettings(ActionExpressionDto $actionExpression): Result
	{
		$documentTypeResult = $this->resolveAuthorizedDocumentType($actionExpression->rawActivityData);
		if (!$documentTypeResult->isSuccess())
		{
			return $documentTypeResult;
		}

		$documentType = $documentTypeResult->getData()['documentType'];
		$rawActivityData = $this->modifyRawActivityDataTemplate(
			$this->replaceNestedDocumentType($actionExpression->rawActivityData, $documentType),
		);

		$activityName = (string)$rawActivityData['id'];
		$isActivated = $rawActivityData['activated'] ?? 'Y';

		[
			'template' => $workflowTemplate,
			'parameters' => $workflowParameters,
			'variables' => $workflowVariables,
			'constants' => $workflowConstants,
			'properties' => $activityProperties,
		] = $this->decodeActivitySettings($rawActivityData, $documentType);

		$result
			= (new Activity\Settings\SaveCommand(
				new Activity\Settings\SaveCommandDto(
					activity: new Activity\Settings\SaveCommandActivityDto(
						type: (string)($rawActivityData['activityType'] ?? ''),
						name: $activityName,
						properties: $activityProperties,
						title: $activityProperties['title'] ?? '',
						isActivated: $isActivated === 'Y',
					),
					documentType: $documentType,
					template: $workflowTemplate,
					variables: $workflowVariables,
					parameters: $workflowParameters,
					constants: $workflowConstants,
				),
				// The document type the expression is settled under is authorized above, so the labels of the
				// signature are read under an access this request checked.
				$this->readableExpressionContextFactory()->createForAuthorizedSchema(...),
			))
				->run()
		;

		// run() is typed as Result: a command may answer from beforeRun() without its own result type. Such an
		// answer carries no settings to normalise the expression with, so it is refused instead of being
		// counted as a save.
		if ($result->isSuccess() && !$result instanceof Activity\Settings\SaveCommandResult)
		{
			return (new Result())->addError(ErrorMessage::UNKNOWN_ERROR->getCodedError());
		}

		return $result;
	}

	/**
	 * Normalise BASE_SETTINGS constructions: rawActivityData → activityData.Properties.
	 * Mirrors processActionExpressions() so that ConvertRuleCommand::mergeBaseSettingsProperties()
	 * can read activityData.Properties after SaveSingleRule completes.
	 */
	private function processBaseSettingsExpressions(PortRuleDto $resultPortRule): Result
	{
		Loader::requireModule('bizproc');

		foreach ($this->extractBaseSettingsExpressionList($resultPortRule) as $addressedExpression)
		{
			$result = $this->normalizeBaseSettingsExpression($addressedExpression['expression']);
			if (!$result->isSuccess())
			{
				return $this->addressFailure($result, $addressedExpression['constructionId']);
			}
		}

		return new Result();
	}

	/**
	 * Normalise one BASE_SETTINGS construction. Mirrors normalizeActionExpression(), the address of a
	 * failure included: it belongs to the traversal above and not to this step.
	 */
	private function normalizeBaseSettingsExpression(BaseSettingsExpressionDto $expression): Result
	{
		if (empty($expression->rawActivityData))
		{
			return $this->revalidateNormalizedNodeAction($expression->activityData);
		}

		$triggerTypeResult = $this->applyNodeTriggerType($expression);
		if (!$triggerTypeResult->isSuccess())
		{
			return $triggerTypeResult;
		}

		// Node-action-backed base-settings: extract field values through the node-action's
		// getPropertiesMap (FieldType::extractValue), internalising printable USER/entity values
		// ("Admin Adminov [1]" -> "user_1") exactly like ACTION constructions do. This must NOT go
		// through the shared getActivitySettings() pipeline: a legacy dual activity (robot +
		// node-action, e.g. IMNotifyActivity) extracts via its own getPropertiesDialogValues, which
		// reads legacy dialog field names and never sees the getPropertiesMap FieldNames that the
		// base-settings form submits - so every field value is dropped and the raw printable string
		// reaches the USER validator.
		$nodeActionResult = $this->extractNodeActionBaseSettings($expression);
		if ($nodeActionResult !== null)
		{
			return $nodeActionResult;
		}

		// Guard: an empty id makes modifyRawActivityDataTemplate inject an empty-string key
		// into the workflow template, so findActivityByName returns null and all field values
		// are silently lost. Skip the getActivitySettings pipeline; the existing rawActivityData
		// (if any) remains as-is, which is the safe fallback for a malformed payload.
		if ((string)($expression->rawActivityData['id'] ?? '') === '')
		{
			return new Result();
		}

		$saveActivityResult = $this->getActivitySettings(
			$this->makeActionExpressionForBaseSettings($expression),
		);

		if (
			!$saveActivityResult instanceof Activity\Settings\SaveCommandResult
			|| !$saveActivityResult->isSuccess()
		)
		{
			return $saveActivityResult;
		}

		$expression->rawActivityData = null;
		$activityData = $saveActivityResult->getSettings()?->toArray() ?? [];
		if (!empty($activityData))
		{
			// A node-action proxy owns a document of its own, exactly as an ACTION does. A host-merged
			// construction describes the node itself and has none: whose document the node works over
			// is written in its own Properties, not here.
			$activityData['Document'] = $expression->actionId !== null ? $expression->document : null;
		}

		$expression->activityData = $activityData;

		return new Result();
	}

	/**
	 * The failure of a construction leaves the traversal addressed: every error is re-assembled carrying the
	 * id of the construction in customData, the very address the settings panel reveals a blocked row by.
	 *
	 * The message and the code are carried over verbatim - other consumers branch on the code, and the
	 * message comes from the activity, being the text that names the field left empty. The message is taken
	 * as it is rather than through getMessage(), which flattens a localizable message into a string.
	 */
	private function addressFailure(Result $failure, string $constructionId): Result
	{
		$addressedFailure = new Result();

		foreach ($failure->getErrors() as $error)
		{
			$addressedFailure->addError(
				new Error(
					$error->getLocalizableMessage() ?? $error->getMessage(),
					$error->getCode(),
					['constructionId' => $constructionId],
				),
			);
		}

		return $addressedFailure;
	}

	/**
	 * The class of the node is not chosen in the editor: it either stays as it is or is replaced by the
	 * upgrade the owner module declares ({@see TriggerUpgradeResolver}). The replacement itself belongs to the
	 * shared save handler further down the pipeline
	 * ({@see \Bitrix\BizprocDesigner\Public\Command\Activity\Settings\SaveCommandHandler}), so the class the
	 * node carries is what is handed to it - the submitted one is only ever checked against it.
	 *
	 * The handler cannot answer for this path on its own: the template it reads the current class from is
	 * rebuilt here from the submitted one ({@see self::modifyRawActivityDataTemplate()}), so a class made up by
	 * the client would reach it as the class of the node. Both spellings the editor legitimately sends - the
	 * class of the node and its upgrade target, since the form of a deprecated node is built from the latter -
	 * are accepted, and anything else is refused with the code the ordinary settings path answers with, so the
	 * unified panel is not a way around it.
	 *
	 * A node-action proxy construction is left alone: it becomes a child activity of its own, and its class is
	 * the sub-action the user picked, not the class of the node.
	 */
	private function applyNodeTriggerType(BaseSettingsExpressionDto $expression): Result
	{
		if ($expression->actionId !== null)
		{
			return new Result();
		}

		$sourceType = $this->resolveNodeActivityType($expression);
		if ($sourceType === '')
		{
			return new Result();
		}

		$rawActivityData = $expression->rawActivityData ?? [];
		$savedActivityData = $expression->activityData ?? [];

		// The nested type is read here only to ask the owner module which class replaces the one the node
		// carries, and no name of that document is read out of it: the checked type belongs to the paths that
		// do read them ({@see self::resolveAuthorizedDocumentType()}), and a payload refused there is refused
		// before anything of it reaches the client.
		$documentTypeResult = $this->extractDocumentType($rawActivityData);
		if (!$documentTypeResult->isSuccess())
		{
			return $documentTypeResult;
		}

		$resolver = new TriggerUpgradeResolver();
		$upgrade = $resolver->resolveUpgradedType(
			$sourceType,
			$resolver->resolveNodeDocumentType(
				[
					$rawActivityData['Document'] ?? null,
					$savedActivityData['Properties']['Document'] ?? null,
				],
				$documentTypeResult->getData()['documentType'] ?? $this->documentType,
			),
		);

		$submittedType = (string)($rawActivityData['activityType'] ?? '');
		if (
			!$resolver->isSameType($submittedType, $sourceType)
			&& !$resolver->isSameType($submittedType, $upgrade['type'])
		)
		{
			return (new Result())->addError(
				new Error('Trigger type transition is not allowed', 'TRIGGER_TYPE_TRANSITION_NOT_ALLOWED'),
			);
		}

		$expression->rawActivityData['activityType'] = $sourceType;

		return new Result();
	}

	/**
	 * The class the node carries now, read before the pipeline is entered:
	 * modifyRawActivityDataTemplate() overwrites the node with the submitted class further down.
	 *
	 * The node of the template the form submitted answers first - it is the very source the shared save
	 * handler reads the class from, so the two checks cannot disagree about the node - and the activity
	 * data saved for the construction is only the fallback for a payload carrying no template. The final
	 * word is not here either way: the full save checks the construction against the host activity of its
	 * own request ({@see ValidateSingleRuleCommand}) before the properties are merged into the node.
	 */
	private function resolveNodeActivityType(BaseSettingsExpressionDto $expression): string
	{
		$rawActivityData = $expression->rawActivityData ?? [];
		$activityName = (string)($rawActivityData['id'] ?? '');
		if ($activityName !== '')
		{
			$template = $this->decodeTemplateData($rawActivityData)['template'];
			$node = is_array($template)
				? \CBPWorkflowTemplateLoader::findActivityByName($template, $activityName)
				: null;

			$templateType = is_array($node) ? (string)($node['Type'] ?? '') : '';
			if ($templateType !== '')
			{
				return $templateType;
			}
		}

		return (string)(($expression->activityData ?? [])['Type'] ?? '');
	}

	/**
	 * The expressions to normalise, each next to the id of the construction carrying it, symmetrically to
	 * extractActionExpressionList().
	 *
	 * @param PortRuleDto $portRuleDto
	 * @return list<array{constructionId: string, expression: BaseSettingsExpressionDto}>
	 */
	private function extractBaseSettingsExpressionList(PortRuleDto $portRuleDto): array
	{
		$list = [];

		foreach ($portRuleDto->rules as $rule)
		{
			foreach ($rule->constructions as $construction)
			{
				if ($construction->constructionType !== ConstructionType::BASE_SETTINGS)
				{
					continue;
				}

				$expression = $construction->expression;
				if (!$expression instanceof BaseSettingsExpressionDto)
				{
					continue;
				}

				$list[] = [
					'constructionId' => $construction->id,
					'expression' => $expression,
				];
			}
		}

		return $list;
	}

	/**
	 * Wrap a BaseSettingsExpressionDto into a minimal ActionExpressionDto so that
	 * the shared getActivitySettings() pipeline (prepareForm / decodeActivitySettings)
	 * can process it. The pipeline reads nothing but rawActivityData, so actionId and document are left
	 * null here; the document of the construction is written onto the settings by the caller.
	 */
	private function makeActionExpressionForBaseSettings(BaseSettingsExpressionDto $expression): ActionExpressionDto
	{
		return new ActionExpressionDto(
			actionId: null,
			rawActivityData: $expression->rawActivityData,
			activityData: $expression->activityData,
			document: null,
		);
	}

	/**
	 * Normalise a node-action-backed BASE_SETTINGS construction: rawActivityData → activityData.
	 *
	 * Field values are extracted through the backing node-action's getPropertiesMap using
	 * FieldType::extractValue (the same mechanism BaseActivity node-actions use for ACTION blocks),
	 * which internalises printable values (USER "Admin Adminov [1]" → "user_1", etc.) before the
	 * value is persisted or validated. Returns:
	 *   - null           — the construction is not node-action-backed → caller falls back to the
	 *                      host-merge getActivitySettings() pipeline (legacy behavior unchanged);
	 *   - Result(errors) — extraction/validation failed;
	 *   - Result(ok)     — activityData populated, rawActivityData cleared.
	 */
	private function extractNodeActionBaseSettings(BaseSettingsExpressionDto $expression): ?Result
	{
		$rawActivityData = $expression->rawActivityData;
		$activityType = (string)($rawActivityData['activityType'] ?? '');

		if (!$this->isNodeActionBaseSettings($expression, $activityType))
		{
			return null;
		}

		$internalizeResult = $this->internalizeNodeActionProperties($activityType, $rawActivityData);
		if ($internalizeResult === null)
		{
			return null;
		}

		if (!$internalizeResult->isSuccess())
		{
			return $internalizeResult;
		}

		[
			'properties' => $properties,
			'activated' => $isActivated,
		] = $internalizeResult->getData();

		$expression->rawActivityData = null;
		$expression->activityData = [
			'Name' => (string)($rawActivityData['id'] ?? ''),
			'Type' => $activityType,
			'Activated' => $isActivated ? 'Y' : 'N',
			'Properties' => $properties,
			'Document' => $expression->document,
		];

		return new Result();
	}

	/**
	 * Internalise a unified node-action's field values from rawActivityData.
	 *
	 * Values are extracted through the backing node-action's getPropertiesMap using
	 * FieldType::extractValue (printable USER "Admin Adminov [1]" → "user_1", etc.) and then validated
	 * with the node-action's own ValidateProperties — exactly what a correct getPropertiesDialogValues
	 * does for the unified (getPropertiesMap-rendered) form.
	 *
	 * The extraction is applied only when the activity actually renders that unified form, i.e. when
	 * CBPActivity::createConfigurator() resolves a non-empty propertiesMap — which happens exactly when
	 * the class implements IBPConfigurableActivity. This is the same gate ActivityControlsBuilder uses to
	 * build the form, so extraction and rendering stay consistent by construction. Legacy-dialog
	 * activities (e.g. CBPSetFieldActivity, CBPCrmCreateToDoActivity) do not implement
	 * IBPConfigurableActivity, resolve an empty map here, and are left to the shared getActivitySettings()
	 * pipeline unchanged. A legacy dual activity (robot + node-action, e.g. CBPIMNotifyActivity) does
	 * render the unified form but extracts via its own legacy getPropertiesDialogValues, which reads
	 * legacy dialog field names and never sees the getPropertiesMap FieldNames the form submits — so
	 * getActivitySettings() drops every value; this helper is what fixes it.
	 *
	 * @return Result|null
	 *   - null           the activity does not render the unified form → caller falls back to getActivitySettings;
	 *   - Result(errors) extraction/validation failed;
	 *   - Result(ok)     data: ['properties' => array, 'activated' => bool].
	 */
	private function internalizeNodeActionProperties(
		string $activityType,
		array $rawActivityData,
		?array $propertiesMap = null,
	): ?Result
	{
		if ($activityType === '')
		{
			return null;
		}

		Loader::requireModule('bizproc');

		$documentTypeResult = $this->resolveAuthorizedDocumentType($rawActivityData);
		if (!$documentTypeResult->isSuccess())
		{
			return $documentTypeResult;
		}

		$documentType = $documentTypeResult->getData()['documentType'];

		['properties' => $request] = $this->decodeActivitySettings(
			$this->replaceNestedDocumentType($rawActivityData, $documentType),
			$documentType,
		);
		$request = is_array($request) ? $request : [];

		// The document type must reach getPropertiesMap(): fields whose metadata depends on it (select
		// Options, document field lists) build empty otherwise, and FieldType::extractValue then drops the
		// submitted value. This is the same document type ActivityControlsBuilder renders the form with.
		// The scheme slice travels with it for the same reason: a map that reads the host node (its filter
		// names the actual source type, the declared one only stands in for the area) would otherwise build
		// its Options for a different type here than the form was rendered with, and extractValue would
		// drop a correctly chosen value as one outside the list.
		$propertiesMap ??= \CBPActivity::createConfigurator(
			$activityType,
			$request,
			$documentType,
			$this->getPropertiesMapContext(),
		)->getPropertiesMap();
		if (empty($propertiesMap))
		{
			// Not a unified (getPropertiesMap-rendered) node-action — defer to the shared pipeline.
			return null;
		}

		$documentService = \CBPRuntime::getRuntime()->getDocumentService();
		$properties = [];
		$errors = [];
		foreach ($propertiesMap as $propertyKey => $fieldProperties)
		{
			$fieldReference = ['Field' => (string)($fieldProperties['FieldName'] ?? $propertyKey)];
			$field = $documentService->getFieldTypeObject($documentType, $fieldProperties);
			if ($field)
			{
				$fieldErrors = [];
				$value = $field->extractValue($fieldReference, $request, $fieldErrors);
				if (!empty($fieldErrors))
				{
					$errors = array_merge($errors, $fieldErrors);
				}
			}
			else
			{
				$value = $documentService->getFieldInputValue(
					$documentType,
					$fieldProperties,
					$fieldReference,
					$request,
					$errors,
				);
			}

			// Apply the field's declared Default when the unified form omitted the value
			// (null / empty string), mirroring PropertiesDialog::setCurrentValues(). A node-action
			// need not render every configuration field it declares (hidden technical fields such as
			// charset or delivery mode); without this, those fields internalise as null and either
			// fail the node-action's own ValidateProperties or drop the robot's declared defaults at
			// runtime. Keyed only off the field's own Default metadata — no per-activity branching.
			if (
				($value === null || $value === '')
				&& array_key_exists('Default', $fieldProperties)
			)
			{
				$value = $fieldProperties['Default'];
			}

			// Declarative Setter — the symmetric counterpart of a map field's 'Getter'. A field may
			// need to split its single form value across several legacy properties (e.g. a recipients
			// field whose value Execute() reads from a companion *Array property plus a literal string).
			// When declared, the Setter owns the write and returns [legacyPropertyName => value, ...];
			// otherwise the value is stored under the field's own key. Keyed only off the field's own
			// metadata — no per-activity branching. Robot dialogs never reach this bridge, so declaring
			// a Setter cannot affect the robot save path.
			$setter = $fieldProperties['Setter'] ?? null;
			if ($setter instanceof \Closure)
			{
				$split = $setter($value);
				if (is_array($split))
				{
					foreach ($split as $legacyKey => $legacyValue)
					{
						$properties[$legacyKey] = $legacyValue;
					}
				}
			}
			else
			{
				$properties[$propertyKey] = $value;
			}
		}

		$isActivated = ($rawActivityData['activated'] ?? 'Y') === 'Y';

		// Enforce the node-action's own required-field validation on the internalised values, matching
		// the save-time validation getActivitySettings() runs for activated activities.
		if (empty($errors) && $isActivated)
		{
			$validationErrors = \CBPActivity::callStaticMethod(
				$activityType,
				'ValidateProperties',
				[$properties, new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser)],
			);
			if (is_array($validationErrors))
			{
				$errors = array_merge($errors, $validationErrors);
			}
		}

		$result = new Result();
		if (!empty($errors))
		{
			foreach ($errors as $error)
			{
				$message = is_array($error) ? (string)($error['message'] ?? '') : (string)$error;
				$code = is_array($error) ? (string)($error['code'] ?? '') : '';
				$result->addError(new Error($message, $code));
			}

			return $result;
		}

		$result->setData([
			'properties' => $properties,
			'activated' => $isActivated,
			'title' => trim((string)($request['title'] ?? '')),
		]);

		return $result;
	}

	private function getPropertiesMapContext(): array
	{
		return $this->templateSlice === [] ? [] : [
			'Template' => $this->templateSlice,
			'WorkflowDocumentType' => $this->documentType,
		];
	}

	/**
	 * Rebuilds an already normalized relation action through the contextual property map used by its form.
	 * Only relation saves carry templateSlice; empty properties remain a compatible untouched placeholder.
	 */
	private function revalidateNormalizedNodeAction(?array &$activityData): Result
	{
		if ($this->templateSlice === [] || empty($activityData))
		{
			return new Result();
		}

		$activityType = (string)($activityData['Type'] ?? '');
		if (!$this->isNodeActionBackingType($activityType))
		{
			return new Result();
		}

		$storedProperties = is_array($activityData['Properties'] ?? null)
			? $activityData['Properties']
			: []
		;
		if ($storedProperties === [])
		{
			return new Result();
		}

		$propertiesMap = \CBPActivity::createConfigurator(
			$activityType,
			$storedProperties,
			$this->documentType,
			$this->getPropertiesMapContext(),
		)->getPropertiesMap();
		if ($propertiesMap === [])
		{
			return new Result();
		}

		$dialog = new PropertiesDialog('', []);
		$rawActivityData = [];
		foreach ($propertiesMap as $propertyKey => $fieldProperties)
		{
			$value = $storedProperties[$propertyKey] ?? ($fieldProperties['Default'] ?? null);
			$getter = $fieldProperties['Getter'] ?? null;
			if ($getter instanceof \Closure)
			{
				$value = $getter($dialog, $fieldProperties, $activityData, false);
			}

			$fieldName = (string)($fieldProperties['FieldName'] ?? $propertyKey);
			$rawActivityData[$fieldName] = $value;
		}

		$rawActivityData['id'] = (string)($activityData['Name'] ?? '');
		$rawActivityData['activityType'] = $activityType;
		$rawActivityData['activated'] = (string)($activityData['Activated'] ?? 'Y');
		if (array_key_exists('Title', $storedProperties))
		{
			$rawActivityData['title'] = $storedProperties['Title'];
		}

		$result = $this->internalizeNodeActionProperties($activityType, $rawActivityData, $propertiesMap);
		if ($result === null || !$result->isSuccess())
		{
			return $result ?? new Result();
		}

		$data = $result->getData();
		$properties = $data['properties'];
		if (array_key_exists('Title', $storedProperties))
		{
			$properties['Title'] = $data['title'];
		}
		$activityData['Properties'] = $properties;

		return new Result();
	}

	private function processConditionExpressions(PortRuleDto $resultPortRule): Result
	{
		$conditionDocumentType = $this->resolveHostNodeConditionDocumentType($resultPortRule);

		foreach ($this->extractConditionExpressionList($resultPortRule) as $conditionExpression)
		{
			$field = $conditionExpression->field;
			if ($field === null)
			{
				continue;
			}

			$conditionExpression->value = $this->resolveConditionValue(
				$field,
				$conditionExpression->value,
				$this->documentType,
				$conditionDocumentType,
			);
		}

		return new Result();
	}

	/**
	 * Document type the `Document` object of the conditions of this rule addresses: for a trigger the document
	 * of its event, and null for every other node, whose condition belongs to the template
	 * ({@see ConditionValueResolver::resolveConditionDocumentType()}).
	 *
	 * The node is read off the base settings of the same rules container - the construction the unified panel
	 * carries the node itself in - and off them as this run leaves them, base settings being normalised before
	 * the conditions are. A container arriving without them names no node, and the document type of the template
	 * keeps answering, exactly as it did before.
	 */
	private function resolveHostNodeConditionDocumentType(PortRuleDto $portRule): ?array
	{
		Loader::requireModule('bizproc');

		foreach ($this->extractBaseSettingsExpressionList($portRule) as $addressedExpression)
		{
			$expression = $addressedExpression['expression'];

			// A node-action proxy construction describes a child activity of its own, not the host node.
			if ($expression->actionId !== null)
			{
				continue;
			}

			$activityData = $expression->activityData ?? [];
			$properties = $activityData['Properties'] ?? [];
			$conditionDocumentType = $this->resolveConditionDocumentType(
				(string)($activityData['Type'] ?? ''),
				is_array($properties) ? $properties : [],
			);
			if ($conditionDocumentType !== null)
			{
				return $conditionDocumentType;
			}
		}

		return null;
	}

	/**
	 * @param PortRuleDto $portRuleDto
	 * @return list<ConditionExpressionDto>
	 */
	private function extractConditionExpressionList(PortRuleDto $portRuleDto): array
	{
		$list = [];

		foreach ($portRuleDto->rules as $rule)
		{
			foreach ($rule->constructions as $construction)
			{
				if (
					$construction->constructionType->isCondition()
					&& $construction->expression instanceof ConditionExpressionDto
				)
				{
					$list[] = $construction->expression;
				}
			}
		}

		return $list;
	}

	/**
	 * Document type one expression is settled under: the type nested in its payload, or the type of the
	 * request when it nests none.
	 *
	 * A nested type is a second document type in one request, while the request was authorized on the type of
	 * its own argument alone ({@see \Bitrix\BizprocDesigner\Infrastructure\Controller\Activity\Complex::saveRuleAction()}).
	 * Everything downstream reads that type as an authorized one - the fields of the document are named by it
	 * and the readable form of an expression is resolved against it - so the right to create a workflow of it
	 * is required here, the very check the entrances of the panel make on the type they are called with.
	 *
	 * A nested type equal to the type of the request is taken as it stands: that one is authorized already,
	 * and re-deciding it here would answer a checked request by a second opinion of the same check.
	 *
	 * @return Result data: ['documentType' => array] - the type the expression is settled under;
	 *   ACCESS_DENIED when the nested type is not a registered document or the user may not operate it.
	 */
	private function resolveAuthorizedDocumentType(array $rawActivityData): Result
	{
		$extractResult = $this->extractDocumentType($rawActivityData);
		if (!$extractResult->isSuccess())
		{
			return $extractResult;
		}

		$nestedDocumentType = $extractResult->getData()['documentType'] ?? null;
		if ($nestedDocumentType === null || $this->isSameDocumentType($nestedDocumentType, $this->documentType))
		{
			return (new Result())->setData(['documentType' => $this->documentType]);
		}

		if (!$this->canCreateWorkflow($nestedDocumentType))
		{
			return (new Result())->addError(ErrorMessage::ACCESS_DENIED->getCodedError());
		}

		return (new Result())->setData(['documentType' => $nestedDocumentType]);
	}

	/**
	 * One instance per command, shared by every expression it settles: each names its own declarations and
	 * its own steps, while the descriptors they have in common are described once.
	 */
	private function readableExpressionContextFactory(): ReadableExpressionContextFactory
	{
		return $this->readableExpressionContextFactory ??= new ReadableExpressionContextFactory();
	}

	private function canCreateWorkflow(array $documentType): bool
	{
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);

		return \CBPDocument::CanUserOperateDocumentType(
			\CBPCanUserOperateOperation::CreateWorkflow,
			$user->getId(),
			$documentType,
		);
	}

	/** The very normalisation the panel paths compare a submitted document type with a stored one by. */
	private function isSameDocumentType(array $documentType, array $otherDocumentType): bool
	{
		return array_map(strval(...), array_values($documentType))
			=== array_map(strval(...), array_values($otherDocumentType))
		;
	}

	/**
	 * Past extractDocumentType() the nested key must no longer carry the client value: decodeActivitySettings()
	 * keeps it among the properties that go on to unConvertProperties(), createConfigurator() and
	 * getPropertiesDialogValues(). Nothing reads it there today, so the checked type replaces the raw value
	 * instead of the key being dropped - a reader appearing later gets a usable type, not a missing key. A
	 * payload that carried no such key does not get one.
	 */
	private function replaceNestedDocumentType(array $rawActivityData, array $documentType): array
	{
		if (array_key_exists('documentType', $rawActivityData))
		{
			$rawActivityData['documentType'] = $documentType;
		}

		return $rawActivityData;
	}

	private function modifyRawActivityDataTemplate(array $rawActivityData): array
	{
		$activityName = (string)($rawActivityData['id'] ?? '');
		$workflowTemplate = Json::decode($rawActivityData['arWorkflowTemplate'] ?? '[]');

		$workflowTemplate[$activityName] = [
			'Name' => $activityName,
			'Properties' => [],
			'Activated' => 'Y',
			'Type' => $rawActivityData['activityType'] ?? '',
		];

		$rawActivityData['arWorkflowTemplate'] = Json::encode($workflowTemplate);

		return $rawActivityData;
	}
}
