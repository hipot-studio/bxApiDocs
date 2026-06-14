<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc;

class CBPMixedCondition extends CBPActivityCondition
{
	public $condition = null;

	public function __construct($condition)
	{
		$this->condition = $condition;
	}

	public function evaluate(CBPActivity $ownerActivity)
	{
		if (!$this->isConditionGroupExist())
		{
			return true;
		}

		$this->conditionGroupToArray();

		$rootActivity = $ownerActivity->getRootActivity();

		$items = [];
		foreach ($this->condition as $cond)
		{
			[$property, $value] = $ownerActivity->getRuntimeProperty($cond['object'], $cond['field'], $rootActivity);
			$fieldTypeObject = $this->getFieldTypeObject($rootActivity, $property);

			$conditionValue = self::additionalExtractValue($fieldTypeObject, $cond['value']);

			$items[] = [
				'joiner' => $this->getJoiner($cond),
				'operator' => $cond['operator'],
				'valueToCheck' => $value,
				'fieldType' => $fieldTypeObject,
				'value' => $cond['object'] ? $rootActivity->parseValue($conditionValue, $property['Type'] ?? 'string') : null,
				'fieldName' => $property['Name'] ?? $cond['field'],
			];
		}

		$conditionGroup = new Bizproc\Activity\ConditionGroup([
			'items' => $items,
			'parameterDocumentId' => $rootActivity->getDocumentId(),
		]);

		$result = $conditionGroup->evaluate();
		if ($ownerActivity->workflow->isDebug())
		{
			$this->writeAutomationConditionLog($items, $conditionGroup->getEvaluateResults(), $result, $ownerActivity);
		}

		return $result;
	}

	public function collectUsages(CBPActivity $ownerActivity)
	{
		$usages = [];
		foreach ($this->condition as $cond)
		{
			$usages[] = Bizproc\Workflow\Template\SourceType::getObjectSourceType($cond['object'], $cond['field']);
			if (is_string($cond['value']))
			{
				$this->collectExpressionUsages($usages, $ownerActivity, $cond['value']);
			}
		}

		return $usages;
	}

	public static function GetPropertiesDialog(
		$documentType,
		$arWorkflowTemplate,
		$arWorkflowParameters,
		$arWorkflowVariables,
		$defaultValue,
		$arCurrentValues = null,
		$formName = "",
		$popupWindow = null,
		$currentSiteId = null,
		$arWorkflowConstants = null
	)
	{
		$runtime = CBPRuntime::getRuntime();
		$documentService = $runtime->getDocumentService();
		$arFieldTypes = $documentService->getDocumentFieldTypes($documentType);

		if (is_array($arCurrentValues))
		{
			$defaultValue = static::GetPropertiesDialogValues(
				$documentType,
				$arWorkflowTemplate,
				$arWorkflowParameters,
				$arWorkflowVariables,
				$arCurrentValues,
				$errors,
				$arWorkflowConstants
			);
		}

		$arCurrentValues = ['conditions' => []];
		if (is_array($defaultValue))
		{
			foreach ($defaultValue as $cond)
			{
				$property = static::getDialogProperty(
					$cond['object'],
					$cond['field'],
					$documentType,
					$arWorkflowTemplate,
					$arWorkflowParameters,
					$arWorkflowVariables,
					$arWorkflowConstants
				);
				if ($property)
				{
					$cond['__property__'] = $property;
					$arCurrentValues['conditions'][] = $cond;
				}
			}
		}
		if (!$arCurrentValues['conditions'])
		{
			$arCurrentValues['conditions'][] = ['operator' => '!empty'];
		}

		return $runtime->ExecuteResourceFile(
			__FILE__,
			'properties_dialog.php',
			[
				'arCurrentValues' => $arCurrentValues,
				'documentService' => $documentService,
				'documentType' => $documentType,
				'arProperties' => $arWorkflowParameters,
				'arVariables' => $arWorkflowVariables,
				'formName' => $formName,
				'arFieldTypes' => $arFieldTypes,
			]
		);
	}

	public static function GetPropertiesDialogValues(
		$documentType,
		$arWorkflowTemplate,
		$arWorkflowParameters,
		$arWorkflowVariables,
		$arCurrentValues,
		&$errors,
		$arWorkflowConstants = null
	)
	{
		$errors = [];

		if (!array_key_exists('mixed_condition', $arCurrentValues) || !is_array($arCurrentValues['mixed_condition']))
		{
			$errors[] = [
				'code' => '',
				'message' => \Bitrix\Main\Localization\Loc::getMessage('BPMC_EMPTY_CONDITION'),
			];

			return null;
		}

		$result = [];
		foreach ($arCurrentValues['mixed_condition'] as $index => $condition)
		{
			$property = static::getDialogProperty(
				$condition['object'],
				$condition['field'],
				$documentType,
				$arWorkflowTemplate,
				$arWorkflowParameters,
				$arWorkflowVariables,
				$arWorkflowConstants
			);

			if (!$property)
			{
				continue;
			}

			$inputResult = static::getConditionFieldInputValue(
				$condition['operator'],
				$documentType,
				$property,
				'mixed_condition_value_' . $index,
				$arCurrentValues,
			);
			if (!$inputResult->isSuccess())
			{
				foreach ($inputResult->getErrors() as $error)
				{
					$errors[] = [
						'message' => $error->getMessage(),
						'code' => $error->getCode(),
					];
				}
			}

			$result[] = [
				'object' => $condition['object'],
				'field' => $condition['field'],
				'operator' => $condition['operator'],
				'value' => $inputResult->getData()['value'] ?? '',
				'joiner' => (int)($condition['joiner'] ?? 0),
			];
		}

		if (count($result) <= 0)
		{
			$errors[] = [
				'code' => '',
				'message' => \Bitrix\Main\Localization\Loc::getMessage('BPMC_EMPTY_CONDITION'),
			];

			return null;
		}

		return $result;
	}

	private static function getDialogProperty(
		$object,
		$field,
		$documentType,
		$template,
		$parameters,
		$variables,
		$constants
	): ?array
	{
		switch ($object)
		{
			case 'Template':
			case 'Parameter':
				return $parameters[$field] ?? null;
			case 'Variable':
				return $variables[$field]?? null;
			case 'Constant':
				if (is_array($constants))
				{
					return $constants[$field] ?? null;
				}
				break;
			case 'GlobalConst':
				return Bizproc\Workflow\Type\GlobalConst::getById($field);
			case 'GlobalVar':
				return Bizproc\Workflow\Type\GlobalVar::getById($field);
			case 'Document':
				static $fields;
				if (!$fields)
				{
					$documentService = CBPRuntime::getRuntime()->getDocumentService();
					$fields = $documentService->getDocumentFields($documentType);
				}

				return $fields[$field] ?? null;
			default:
				return self::findActivityProperty($object, $field, $template);
		}

		return null;
	}

	private static function findActivityProperty($object, $field, array $template): ?array
	{
		$activity = self::findTemplateActivity($template, $object);
		if (!$activity)
		{
			return null;
		}

		$props = \CBPRuntime::getRuntime()->getActivityReturnProperties($activity);

		return self::resolveActivityProperty($props, $field);
	}

	private static function resolveActivityProperty(array $props, string $field): ?array
	{
		if (isset($props[$field]))
		{
			return $props[$field];
		}

		if (!str_contains($field, '.'))
		{
			return null;
		}

		[$parentField, $fieldName] = explode('.', $field, 2);
		if (!isset($props[$parentField]))
		{
			return null;
		}

		$property = $props[$parentField];

		if (($property['Type'] ?? '') === Bizproc\FieldType::DOCUMENT && $fieldName)
		{
			$documentType = CBPHelper::normalizeComplexDocumentId($property['Default']);
			if ($documentType)
			{
				$documentService = CBPRuntime::getRuntime()->getDocumentService();
				$documentFields = $documentService->getDocumentFields($documentType);

				return $documentFields[$fieldName] ?? null;
			}
		}

		if (($property['Type'] ?? '') === Bizproc\FieldType::JSON && isset($property['BaseType']))
		{
			$property['Type'] = $property['BaseType'];

			return $property;
		}

		return null;
	}

	private static function findTemplateActivity(array $template, $id)
	{
		foreach ($template as $activity)
		{
			if ($activity['Name'] === $id)
			{
				return $activity;
			}
			if (is_array($activity['Children'] ?? null))
			{
				$found = self::findTemplateActivity($activity['Children'], $id);
				if ($found)
				{
					return $found;
				}
			}
		}

		return null;
	}

	protected function getJoiner($condition): int
	{
		return empty($condition['joiner']) ? static::CONDITION_JOINER_AND : static::CONDITION_JOINER_OR;
	}

	private static function additionalExtractValue(Bizproc\FieldType $fieldType, $value)
	{
		if ($fieldType->getType() === 'user' && is_string($value))
		{
			if (strpos($value, '[') !== false || strpos($value, '{') !== false)
			{
				$errors = [];
				$value = \CBPHelper::UsersStringToArray($value, $fieldType->getDocumentType(), $errors);
			}
		}

		return $value;
	}
}
