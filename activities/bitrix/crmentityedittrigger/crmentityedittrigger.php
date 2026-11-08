<?php

declare(strict_types=1);

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Public\Activity\ReturnDocumentTrait;
use Bitrix\Crm\Integration\BizProc\Activity\Mixins\EventOutputsTrait;
use Bitrix\Crm\Integration\BizProc\Trigger\CategoryProperty;
use Bitrix\Crm\Integration\BizProc\Trigger\CrmEntityTriggerTrait;
use Bitrix\Crm\Integration\BizProc\Trigger\TrackedFieldsProperty;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!CBPRuntime::getRuntime()->includeActivityFile('EditDocumentTrigger'))
{
	return;
}

if (!\Bitrix\Main\Loader::includeModule('crm'))
{
	return;
}

class CBPCrmEntityEditTrigger extends CBPEditDocumentTrigger
{
	use CrmEntityTriggerTrait;
	use EventOutputsTrait;
	use ReturnDocumentTrait;

	private const EVENT_INITIATOR_ID = 'Initiator';
	private const EVENT_DATE_TIME_ID = 'EventDateTime';

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties['Document'] = '';
		$this->arProperties['ReturnDocument'] = null;
		$this->arProperties['IsAutomatedSolution'] = 'N';
		$this->arProperties[self::CATEGORY_ID_PROPERTY] = null;
		// No control of its own any more, but the value saved before the upgrade must survive
		// initializeFromArray(), which only accepts already declared properties.
		$this->arProperties['Fields'] = null;
		$this->arProperties[self::EVENT_INITIATOR_ID] = null;
		$this->arProperties[self::EVENT_DATE_TIME_ID] = null;

		$this->setPropertiesTypes([
			self::EVENT_INITIATOR_ID => ['Type' => FieldType::USER],
			self::EVENT_DATE_TIME_ID => ['Type' => FieldType::DATETIME],
		]);
	}

	/**
	 * The preset phrases are gone with the palette cards, so the preset titles resolve to null and the
	 * legacy template converter falls back to the descriptor name. The prefix override still matters:
	 * without it the presets would silently pick up the create-trigger titles of the trait.
	 */
	protected static function getPresetMessagePrefix(): string
	{
		return 'BP_CRM_%s_EDIT_FCT_DESCR';
	}

	public function execute(): int
	{
		parent::execute();

		$document = $this->getEventData()['Document'] ?? null;
		$initiatorUserId = $this->resolveEventInitiatorUserId();

		$this->setProperties([
			static::getReturnDocumentFieldName() => $document,
			...static::buildEventOutputs($initiatorUserId),
		]);
		$this->setPropertiesTypes(
			static::buildReturnDocumentProperties(is_array($document) ? $document : null)
		);

		return CBPActivityExecutionStatus::Closed;
	}

	/**
	 * The parent stub returns no rules at all, and the checker reads an empty rule set as "applies to any
	 * document", so checkApplyRules() of the CRM trait never runs. parent:: would land on that stub, hence
	 * the grandparent called by its name - a copy of its body would keep the rule format of today while
	 * BaseTrigger moves on.
	 */
	public function createApplyRules(): array
	{
		return \Bitrix\Bizproc\Activity\BaseTrigger::createApplyRules();
	}

	public static function getPropertiesDialogValues(
		$documentType,
		$activityName,
		&$workflowTemplate,
		&$workflowParameters,
		&$workflowVariables,
		$currentValues,
		&$errors,
	): bool
	{
		if (
			class_exists(\Bitrix\Bizproc\Activity\BaseTrigger::class)
			&& method_exists(\Bitrix\Bizproc\Activity\BaseTrigger::class, 'applyPropertiesDialogValues')
		)
		{
			return static::applyPropertiesDialogValues(
				$documentType,
				$activityName,
				$workflowTemplate,
				$workflowParameters,
				$workflowVariables,
				$currentValues,
				$errors,
			);
		}

		return self::applyLegacyPropertiesDialogValues(
			$documentType,
			$activityName,
			$workflowTemplate,
			$workflowParameters,
			$workflowVariables,
			$currentValues,
			$errors,
		);
	}

	private static function applyLegacyPropertiesDialogValues(
		$documentType,
		$activityName,
		&$workflowTemplate,
		&$workflowParameters,
		&$workflowVariables,
		$currentValues,
		&$errors,
	): bool
	{
		$currentValues = is_array($currentValues) ? $currentValues : [];
		$errors = [];
		$properties = [];

		$documentService = \CBPRuntime::getRuntime()->getDocumentService();
		$map = static::getPropertiesMap($documentType, $currentValues);

		foreach ($map as $id => $property)
		{
			$value = $documentService->getFieldInputValue(
				$documentType,
				$property,
				$property['FieldName'],
				$currentValues,
				$errors,
			);

			if (!empty($errors))
			{
				return false;
			}

			$properties[$id] = $value;
		}

		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);
		$errors = static::validateProperties($properties, $user);
		if ($errors)
		{
			return false;
		}

		$properties = static::preparePropertiesDialogValues($documentType, $properties, $currentValues);
		$currentActivity = &\CBPWorkflowTemplateLoader::findActivityByName($workflowTemplate, $activityName);
		$currentActivity['Properties'] = $properties;

		return true;
	}

	/**
	 * The current frontend never reaches this method: the renderer of the deprecated node is an alias of the
	 * current one, which requests the ajax with activity: 'CrmEntityFieldChangedTrigger'. The endpoint
	 * resolves the class by the activity name sent by the client, so the method is kept for the bundles
	 * cached before the alias - they still ask this class for both the tracked Fields and the categories.
	 *
	 * @param $request
	 * @return array{fields: array, categories: array}
	 */
	public static function getAjaxResponse($request): array
	{
		$document = $request['document'] ?? null;
		if (!is_string($document) || $document === '')
		{
			return ['fields' => [], 'categories' => []];
		}

		return [
			'fields' => TrackedFieldsProperty::getOptions($document),
			'categories' => CategoryProperty::getOptions($document),
		];
	}
}
