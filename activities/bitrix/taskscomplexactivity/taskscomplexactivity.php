<?php

declare(strict_types=1);

use Bitrix\Bizproc\Automation\Helper;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Public\Activity\BaseComplexActivity;
use Bitrix\Bizproc\Public\Activity\Interface\FixedDocumentComplexActivity;
use Bitrix\Bizproc\Public\Activity\Interface\NodeFilterMetadataProvider;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Integration\Bizproc\Document\Task;
use Bitrix\Tasks\Internals\TaskTable;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

$runtime = CBPRuntime::GetRuntime();

/** @property-write string|null ErrorMessage */
class CBPTasksComplexActivity extends BaseComplexActivity
	implements FixedDocumentComplexActivity, NodeFilterMetadataProvider
{
	private const NODE_FILTER_ENTITY_KEY = 'TASK';

	private const NODE_FILTER_SUPPORTED_FIELD_TYPES = [
		FieldType::INT,
		FieldType::DOUBLE,
		FieldType::STRING,
		FieldType::TEXT,
		FieldType::BOOL,
		FieldType::DATE,
		FieldType::DATETIME,
		FieldType::SELECT,
		FieldType::USER,
	];

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

	public static function getNodeFilterMetadata(
		array $contextDocumentType,
		bool $onlyDynamicEntities = false,
	): array
	{
		Loc::loadMessages(__FILE__);

		if (!Loader::includeModule('tasks'))
		{
			return [
				'entityTypeOptions' => [],
				'filterFieldsMap' => [],
				'documentTypeMap' => [],
			];
		}

		$documentType = static::getDocumentTypeForNodeAction();

		return [
			'entityTypeOptions' => [
				self::NODE_FILTER_ENTITY_KEY => Loc::getMessage('TASKS_NODE_FILTER_METADATA_ENTITY_TASK'),
			],
			'filterFieldsMap' => [
				self::NODE_FILTER_ENTITY_KEY => self::collectNodeFilterFields($documentType),
			],
			'documentTypeMap' => [
				self::NODE_FILTER_ENTITY_KEY => $documentType,
			],
		];
	}

	private static function collectNodeFilterFields(array $documentType): array
	{
		$fields = [];
		foreach (Helper::getDocumentFields($documentType) as $fieldId => $field)
		{
			if (self::isFilterableField($fieldId, $field))
			{
				$fields[] = $field;
			}
		}

		return $fields;
	}

	private static function isFilterableField(string $fieldId, array $field): bool
	{
		if (!in_array($field['Type'], self::NODE_FILTER_SUPPORTED_FIELD_TYPES, true))
		{
			return false;
		}

		return self::isOrmField($fieldId);
	}

	private static function isOrmField(string $fieldId): bool
	{
		if (str_starts_with($fieldId, 'UF_'))
		{
			return true;
		}

		return isset(self::getOrmFieldIds()[$fieldId]);
	}

	/** @return array<string, true> */
	private static function getOrmFieldIds(): array
	{
		static $ormFieldIds = null;

		if ($ormFieldIds === null)
		{
			$ormFieldIds = [];
			foreach (TaskTable::getEntity()->getScalarFields() as $field)
			{
				$ormFieldIds[$field->getName()] = true;
			}
		}

		return $ormFieldIds;
	}
}
