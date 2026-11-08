<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity;

use Bitrix\Bizproc\Activity\Enum\Joiner;
use Bitrix\Bizproc\Activity\Trigger\TriggerParameters;
use Bitrix\Bizproc\Activity\Trigger\TriggerRule;
use Bitrix\Bizproc\Internal\Entity\Workflow\ExecutionPayload;
use Bitrix\Bizproc\Internal\Entity\Workflow\TestRunContext;
use Bitrix\Bizproc\Public\Entity\Document\DocumentComplexType;
use Bitrix\Bizproc\Public\Entity\Trigger\Section;
use Bitrix\Main\Loader;

abstract class BaseTrigger extends \CBPActivity implements \IBPTriggerActivity, \IBPConfigurableActivity
{
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

	protected static function applyPropertiesDialogValues(
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

	protected static function preparePropertiesDialogValues(
		array $documentType,
		array $properties,
		array $currentValues,
	): array
	{
		return $properties;
	}

	public function createApplyRules(): array
	{
		return ['Properties' => $this->getAllProperties()];
	}

	protected function getAllProperties(): array
	{
		$allProperties = $this->arProperties;
		unset($allProperties['Title']);

		$properties = [];
		foreach (array_keys($allProperties) as $key)
		{
			$properties[$key] = $this->arProperties[$key];
		}

		return $properties;
	}

	/**
	 * @param TriggerRule[] $rules
	 * @param TriggerParameters $parameters
	 *
	 * @return bool
	 */
	protected function checkRules(array $rules, TriggerParameters $parameters): bool
	{
		$items = [];
		// The first rule is always with an AND joiner because of the condition group
		// The second rule says which joiner is between it and the first rule, etc
		$isFirstRule = true;
		foreach ($rules as $rule)
		{
			$items[] = [
				'joiner' => $isFirstRule ? Joiner::And->getInt() : $rule->joiner->getInt(),
				'operator' => $rule->operator->value,
				'value' => $parameters->get($rule->parameterName),
				'valueToCheck' => $rule->value,
				'fieldType' => $rule->fieldType,
			];
			$isFirstRule = false;
		}

		$conditionGroup = new ConditionGroup(['items' => $items]);
		$evaluateResult = $conditionGroup->evaluate();

		foreach ($rules as $index => $rule)
		{
			$rule->setResult($conditionGroup->getEvaluateResults()[$index]);
		}

		return $evaluateResult;
	}

	public function getConfigurator(): \Bitrix\Bizproc\Public\Activity\Configurator
	{
		$configurator = parent::createConfigurator();

		return $configurator
			->setDocumentComplexType(new DocumentComplexType(...$this->getEventDocumentComplexType()))
			->setSection($this->getSection())
		;
	}

	/**
	 * Document type of the event this trigger reacts to, as `[moduleId, entity, documentType]`; the `Workflow`
	 * pseudo-type when the event carries no document of its own. Instance-level on purpose: a trigger of one
	 * class serves several document types, resolving its own from its properties.
	 *
	 * The same value the configurator carries, and thereby the very one
	 * {@see \Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTriggerTable::upsert()} registers the
	 * trigger by. Reachable apart from the configurator because the editor asks for the document type alone
	 * ({@see \Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService::getPublishedDocumentTypeForNode()})
	 * and must not be made to build the section of a trigger to learn it.
	 */
	public function getEventDocumentComplexType(): array
	{
		//temporary
		if (static::getModuleId())
		{
			Loader::includeModule(static::getModuleId());
		}

		return $this->getDocumentComplexType();
	}

	protected static function getModuleId(): ?string
	{
		return null;
	}

	protected function getDocumentComplexType(): array
	{
		return \Bitrix\Bizproc\Public\Entity\Document\Workflow::getComplexType();
	}

	protected function getSection(): ?Section
	{
		return null;
	}

	protected function getEventData(): array
	{
		return $this->getRootActivity()->{\CBPDocument::PARAM_TRIGGER_EVENT_DATA} ?? [];
	}

	/**
	 * A test run publishes the outputs of the trigger itself and never runs the logic of the node: no event
	 * has happened, so there is nothing for {@see self::execute()} to read, subscribe to or wait for. That
	 * holds for every trigger there is, of this module and of any other, without a line in the node.
	 *
	 * The chain of children is started the way the two phases of the engine start it
	 * ({@see \CBPActivity::executeWithPayload()}).
	 *
	 * Only the node a test run was started from is served this way ({@see TestRunContext}); an empty set of
	 * values is a legitimate one, a trigger may publish no outputs at all.
	 */
	public function executeWithPayload(ExecutionPayload $payload)
	{
		$testRun = $this->getTestRunContext();
		if ($testRun === null || !$testRun->isStartNode($this))
		{
			return parent::executeWithPayload($payload);
		}

		$this->setProperties($testRun->values);
		$this->setPropertiesTypes($testRun->types);

		$this->childFlowInputPort = $payload->getInputPort();

		return $this->startChildFlowOnce()
			? \CBPActivityExecutionStatus::Executing
			: \CBPActivityExecutionStatus::Closed
		;
	}
}
