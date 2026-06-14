<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPCreateDocumentTrigger extends \Bitrix\Bizproc\Activity\BaseTrigger
{
	public function execute(): int
	{
		return CBPActivityExecutionStatus::Closed;
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [];
	}

	public static function getPropertiesDialogValues(
		$documentType,
		$activityName,
		&$workflowTemplate,
		&$workflowParameters,
		&$workflowVariables,
		$currentValues,
		&$errors
	): bool
	{
		$properties = [];
		foreach (static::getPropertiesMap($documentType) as $id => $property)
		{
			$properties[$id] = $currentValues[$id] ?? null;
		}

		$currentActivity = &CBPWorkflowTemplateLoader::findActivityByName($workflowTemplate, $activityName);
		$currentActivity['Properties'] = $properties;

		return true;
	}

	public function createApplyRules(): array
	{
		return []; // TODO
	}

	public function checkApplyRules(array $rules, \Bitrix\Bizproc\Activity\Trigger\TriggerParameters $parameters): \Bitrix\Bizproc\Result
	{
		return \Bitrix\Bizproc\Result::createOk();
	}
}
