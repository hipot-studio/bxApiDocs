<?php

declare(strict_types=1);

use Bitrix\Bizproc\Internal\Entity\Activity\Interface\FixedDocumentComplexActivity;
use Bitrix\Bizproc\Public\Activity\BaseComplexActivity;
use Bitrix\Tasks\Integration\Bizproc\Document\Task;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

$runtime = CBPRuntime::GetRuntime();

/** @property-write string|null ErrorMessage */
class CBPTasksComplexActivity extends BaseComplexActivity
	implements FixedDocumentComplexActivity
{
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
		// todo: realize logic, it is just an example

		$currentActivity = &\CBPWorkflowTemplateLoader::findActivityByName($workflowTemplate, $activityName);
		$currentActivity['Properties'] = [
			self::PARAM_LINKS => [],
			self::INPUT_ACTIVITY_NAMES => [],
			self::OUTPUT_ACTIVITY_NAMES => [],
		];

		return true;
	}

	public static function getDocumentTypeForNodeAction(): array
	{
		return ['tasks', Task::class, 'TASK'];
	}
}
