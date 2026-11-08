<?php

namespace Bitrix\Bizproc\Activity\Mixins;

use Bitrix\Bizproc\Automation\Engine\ConditionGroup;
use Bitrix\Bizproc\Automation\Engine\Condition;
use Bitrix\Bizproc\Activity\Enum\Operator;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Result;

trait EntityFilter
{
	private const MAX_MULTIPLE_CONDITION_VALUES = 500;
	private const MAX_MULTIPLE_CONDITION_VALUE_LENGTH = 255;
	private const MAX_MULTIPLE_CONDITION_JSON_LENGTH = 65535;
	private const IMPOSSIBLE_FILTER_VALUE = "\x00__bp_invalid_condition__";

	private ?bool $ormFilterValid = null;
	private bool $ormFilterComplete = false;

	abstract public function getDocumentType();

	public function getOrmFilter(
		ConditionGroup $conditionGroup,
		?array $targetDocumentType = null,
		?array $fieldsMap = null
	): array
	{
		$this->ormFilterValid = true;
		$this->ormFilterComplete = true;
		$filter = ['LOGIC' => 'OR'];

		$documentService = \CBPRuntime::getRuntime()->getDocumentService();
		if (is_null($targetDocumentType))
		{
			$targetDocumentType = $this->getDocumentType();
		}

		if (!$fieldsMap)
		{
			$fieldsMap = $documentService->getDocumentFields($targetDocumentType);
		}

		$i = 0;
		$filter[$i] = [];

		/**@var Condition $condition*/
		foreach ($conditionGroup->getItems() as [$condition, $joiner])
		{
			$fieldId = $condition->getField();
			if (!isset($fieldsMap[$fieldId]))
			{
				$this->ormFilterValid = false;
				$this->ormFilterComplete = false;
				continue;
			}

			if ($condition->getOperator() === \Bitrix\Bizproc\Activity\Operator\BetweenOperator::getCode())
			{
				$betweenFilterResult = $this->getBetweenFilter($fieldsMap[$fieldId] ?? [], $condition);
				if (!$betweenFilterResult->isSuccess())
				{
					$this->ormFilterComplete = false;
					continue;
				}

				$filter[$i][] = $betweenFilterResult->getData()['filter1'];
				$filter[$i][] = $betweenFilterResult->getData()['filter2'];
			}
			elseif ($conditionGroup->isInternalized())
			{
				$value = $condition->getValue();
			}
			else
			{
				$fieldProperties = $fieldsMap[$fieldId];
				$conditionValue = $condition->getValue();
				if (self::isMultiValueOperator($condition->getOperator()))
				{
					$fieldProperties['Multiple'] = true;
					$conditionValue = self::normalizeMultiValueConditionValue($conditionValue);
					if ($conditionValue === null)
					{
						$this->ormFilterValid = false;
						$this->ormFilterComplete = false;

						return $this->getImpossibleOrmFilter($condition->getField());
					}
				}
				elseif (!is_array($conditionValue))
				{
					$conditionValue = (string)$conditionValue;
				}

				$extractionResult = $this->extractValue($fieldProperties, $conditionValue);
				if ($extractionResult->isSuccess())
				{
					$value = $extractionResult->getData()['extractedValue'];
				}
				else
				{
					$this->ormFilterComplete = false;
					continue;
				}
			}

			if (self::isMultiValueOperator($condition->getOperator()) && $conditionGroup->isInternalized())
			{
				$value = self::normalizeMultiValueConditionValue($value);
				if ($value === null)
				{
					$this->ormFilterValid = false;
					$this->ormFilterComplete = false;

					return $this->getImpossibleOrmFilter($condition->getField());
				}
			}

			if ($fieldsMap[$fieldId]['Type'] === FieldType::USER && $value)
			{
				$value = \CBPHelper::extractUsers($value, $targetDocumentType);
			}
			elseif ($fieldsMap[$fieldId]['Type'] === FieldType::BOOL && isset($value))
			{
				$value = \CBPHelper::getBool($value);
			}

			switch ($condition->getOperator())
			{
				case 'empty':
					$operator = '=';
					$value = '';
					break;

				case '!empty':
					$operator = '!=';
					$value = '';
					break;

				case 'in':
					$operator = '@';
					break;

				case '!in':
					$operator = '!@';
					break;

				case 'contain':
					$operator = '%';
					break;

				case '!contain':
					$operator = '!%';
					break;

				case '>':
				case '>=':
				case '<':
				case '<=':
				case '=':
				case '!=':
					$operator = $condition->getOperator();
					break;

				default:
					$operator = '';
					break;
			}

			if (!$operator)
			{
				// A successful between has already contributed both bounds above.
				if ($condition->getOperator() !== \Bitrix\Bizproc\Activity\Operator\BetweenOperator::getCode())
				{
					$this->ormFilterComplete = false;
				}
				continue;
			}

			if (\CBPHelper::isEmptyValue($value) && in_array($operator, ['@', '!@'], true))
			{
				$this->ormFilterComplete = false;
				continue;
			}

			$filter[$i][] = $this->createRowFilter($operator, $condition->getField(), $value);

			if ($joiner === ConditionGroup::JOINER_OR)
			{
				$filter[++$i] = [];
			}
		}

		return $filter;
	}

	protected function isOrmFilterValid(): bool
	{
		return $this->ormFilterValid ?? false;
	}

	protected function isOrmFilterComplete(): bool
	{
		return $this->ormFilterComplete;
	}

	private function getBetweenFilter(array $property, Condition $condition): Result
	{
		$value = $condition->getValue();
		$value_greater_then = (is_array($value) && isset($value[0]) ? $value[0] : $value);
		$value_less_then = (is_array($value) && isset($value[1]) ? $value[1] : '');

		if (!is_array($value_greater_then))
		{
			$value_greater_then = (string)$value_greater_then;
		}
		$extractionResult1 = $this->extractValue($property, $value_greater_then);
		if (!$extractionResult1->isSuccess())
		{
			return Result::createOk()->addErrors($extractionResult1->getErrors());
		}

		if (!is_array($value_less_then))
		{
			$value_less_then = (string)$value_less_then;
		}
		$extractionResult2 = $this->extractValue($property, $value_less_then);
		if (!$extractionResult2->isSuccess())
		{
			return Result::createOk()->addErrors($extractionResult2->getErrors());
		}

		$filter1 = $this->createRowFilter('>=', $condition->getField(), $extractionResult1->getData()['extractedValue']);
		$filter2 = $this->createRowFilter('<=', $condition->getField(), $extractionResult2->getData()['extractedValue']);

		return Result::createOk(['filter1' => $filter1, 'filter2' => $filter2]);
	}

	private function createRowFilter(string $operator, string $field, $value): array
	{
		return [$operator . $field => $value];
	}

	/**
	 * Fail-closed ORM filter: a self-contradictory pair on the condition's own field
	 * matches no rows, so even consumers that ignore isOrmFilterValid()
	 * (crm*dynamic activities, node-filter adapters) select nothing instead of a widened set.
	 */
	private function getImpossibleOrmFilter(string $field): array
	{
		return [
			'LOGIC' => 'OR',
			0 => [
				$this->createRowFilter('=', $field, self::IMPOSSIBLE_FILTER_VALUE),
				$this->createRowFilter('!=', $field, self::IMPOSSIBLE_FILTER_VALUE),
			],
		];
	}

	private static function isMultiValueOperator(string $operator): bool
	{
		return $operator === Operator::In->value || $operator === Operator::NotIn->value;
	}

	private static function normalizeMultiValueConditionValue(mixed $value): mixed
	{
		if (is_array($value))
		{
			return self::sanitizeMultipleConditionValue($value);
		}

		return self::unserializeConditionValue($value);
	}

	private static function unserializeConditionValue(mixed $value): mixed
	{
		if (!is_string($value) || !str_starts_with(ltrim($value), '['))
		{
			return $value;
		}

		if (mb_strlen($value) > self::MAX_MULTIPLE_CONDITION_JSON_LENGTH)
		{
			return null;
		}

		$decoded = json_decode($value, true);
		if (!is_array($decoded) || !array_is_list($decoded))
		{
			return $value;
		}

		return self::sanitizeMultipleConditionValue($decoded);
	}

	private static function sanitizeMultipleConditionValue(array $values): ?array
	{
		if (count($values) > self::MAX_MULTIPLE_CONDITION_VALUES)
		{
			return null;
		}

		$sanitizedValues = [];
		foreach ($values as $element)
		{
			if (!is_scalar($element))
			{
				return null;
			}

			$value = (string)$element;
			if (mb_strlen($value) > self::MAX_MULTIPLE_CONDITION_VALUE_LENGTH)
			{
				return null;
			}

			$sanitizedValues[] = $value;
		}

		return $sanitizedValues;
	}

	protected function extractValue(array $fieldProperties, $value): Result
	{
		$documentService = \CBPRuntime::getRuntime()->getDocumentService();

		$field = $documentService->getFieldTypeObject($this->getDocumentType(), $fieldProperties);

		$errors = [];
		$extractionErrors = [];
		if (!$field)
		{
			$errors[] = new Error('Can\'t create field type object');
		}
		else
		{
			if (!isset($fieldProperties['FieldName']))
			{
				$fieldProperties['FieldName'] = 'field_name';
			}

			$value = $field->extractValue(
				['Field' => $fieldProperties['FieldName']],
				[$fieldProperties['FieldName'] => $value],
				$extractionErrors,
			);
		}

		foreach ($extractionErrors as $singleError)
		{
			if (is_array($singleError))
			{
				$errors[] = new Error(
					$singleError['message'] ?? '',
					$singleError['code'] ?? '',
					$singleError['parameter'] ?? ''
				);
			}
		}

		return $errors ? Result::createOk()->addErrors($errors) : Result::createOk(['extractedValue' => $value]);
	}

	public static function extractFilterFromProperties(PropertiesDialog $dialog, array $fieldsMap): Result
	{
		$currentValues = $dialog->getCurrentValues();
		$prefix = $fieldsMap['DynamicFilterFields']['FieldName'] . '_';

		$conditionGroup = ['items' => []];

		foreach ($currentValues[$prefix . 'field'] ?? [] as $index => $fieldName)
		{
			$operator = $currentValues[$prefix . 'operator'][$index];
			if (
				$operator === \Bitrix\Bizproc\Activity\Operator\BetweenOperator::getCode()
				&& isset($currentValues[$prefix . 'value'][$index], $currentValues[$prefix . 'value'][$index + 1])
			)
			{
				$currentValues[$prefix . 'value'][$index] = [
					$currentValues[$prefix . 'value'][$index],
					$currentValues[$prefix . 'value'][$index + 1],
				];

				array_splice($currentValues[$prefix . 'value'], $index + 1, 1);
			}
			elseif (
				self::isMultiValueOperator($operator)
				&& isset($currentValues[$prefix . 'value'][$index])
			)
			{
				$normalizedValue = self::normalizeMultiValueConditionValue(
					$currentValues[$prefix . 'value'][$index]
				);
				if ($normalizedValue !== null)
				{
					$currentValues[$prefix . 'value'][$index] = $normalizedValue;
				}
			}

			$conditionGroup['items'][] = [
				// condition
				[
					'object' => $currentValues[$prefix . 'object'][$index],
					'field' => $currentValues[$prefix . 'field'][$index],
					'operator' => $operator,
					'value' => $currentValues[$prefix . 'value'][$index],
				],
				// joiner
				$currentValues[$prefix . 'joiner'][$index],
			];
		}

		$result = new Result();
		$result->setData($conditionGroup);

		return $result;
	}
}
