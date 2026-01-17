<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity;

use Bitrix\Bizproc\Activity\Enum\Joiner;
use Bitrix\Bizproc\Activity\Trigger\TriggerParameters;
use Bitrix\Bizproc\Activity\Trigger\TriggerRule;
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
		$errors = [];
		$properties = [];

		$documentService = \CBPRuntime::getRuntime()->getDocumentService();
		$map = static::getPropertiesMap($documentType, is_array($currentValues) ? $currentValues : []);

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

		$currentActivity = &\CBPWorkflowTemplateLoader::findActivityByName($workflowTemplate, $activityName);
		$currentActivity['Properties'] = $properties;

		return true;
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

		//temporary
		if (static::getModuleId())
		{
			Loader::includeModule(static::getModuleId());
		}

		return $configurator
			->setDocumentComplexType(new DocumentComplexType(...$this->getDocumentComplexType()))
			->setSection($this->getSection())
		;
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
}
