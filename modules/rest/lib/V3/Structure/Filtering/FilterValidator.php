<?php

namespace Bitrix\Rest\V3\Structure\Filtering;

use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exception\UnknownFilterOperatorException;
use Bitrix\Rest\V3\Exception\Validation\DtoFieldRequiredAttributeException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Structure\FieldsValidator;
use Bitrix\Rest\V3\Structure\Filtering\Expressions\Expression;

class FilterValidator
{
	/**
	 * @throws UnknownFilterOperatorException
	 */
	public static function validateOperator(string $operator): Operator
	{
		$validOperator = Operator::tryFrom($operator);
		if (!$validOperator)
		{
			throw new UnknownFilterOperatorException($operator);
		}

		return $validOperator;
	}

	/**
	 * @throws DtoFieldRequiredAttributeException
	 * @throws UnknownDtoPropertyException
	 * @throws \ReflectionException
	 * @throws InvalidFilterException|InvalidRequestFieldTypeException
	 */
	public static function validateOperands(Condition $condition, Dto $dto): void
	{
		if (!$condition->getLeftOperand() instanceof Expression)
		{
			if (!isset($dto->getFields()[$condition->getLeftOperand()]))
			{
				throw new UnknownDtoPropertyException($dto->getShortName(), $condition->getLeftOperand());
			}
			if (!$dto->getFields()[$condition->getLeftOperand()]->isFilterable())
			{
				throw new DtoFieldRequiredAttributeException($dto->getShortName(), $condition->getLeftOperand(), Filterable::class);
			}
			if (!$condition->getRightOperand() instanceof Expression)
			{
				self::validateSimpleCondition($condition, $dto);
			}

			$field = $dto->getFields()[$condition->getLeftOperand()];

			if (is_array($condition->getRightOperand()))
			{
				foreach ($condition->getRightOperand() as $rightOperand)
				{
					if (!FieldsValidator::validateTypeAndValue($field->getPropertyType(), $rightOperand))
					{
						throw new InvalidRequestFieldTypeException($field->getPropertyName(), $field->getPropertyType());
					}
				}
			}
			else
			{
				if (!FieldsValidator::validateTypeAndValue($field->getPropertyType(), $condition->getRightOperand()))
				{
					throw new InvalidRequestFieldTypeException($field->getPropertyName(), $field->getPropertyType());
				}
			}
		}

		foreach ($condition->getOperands() as $operand)
		{
			if ($operand instanceof Expression)
			{
				if (!isset($dto->getFields()[$operand->getProperty()]))
				{
					throw new UnknownDtoPropertyException($dto->getShortName(), $operand->getProperty());
				}
				if (!$dto->getFields()[$operand->getProperty()]->isFilterable())
				{
					throw new DtoFieldRequiredAttributeException($dto->getShortName(), $operand->getProperty(), Filterable::class);
				}
			}
		}
	}

	/**
	 * @throws InvalidFilterException
	 */
	private static function validateSimpleCondition(Condition $condition, Dto $dto): void
	{
		switch ($condition->getOperator())
		{
			case Operator::In:
				self::validateInCondition($condition->getOperator(), $condition->getRightOperand());

				break;
			case Operator::Between:
				self::validateBetweenCondition($condition, $dto);

				break;
			case Operator::Equal:
			case Operator::NotEqual:
			case Operator::Greater:
			case Operator::Less:
			case Operator::GreaterOrEqual:
			case Operator::LessOrEqual:
				if (is_array($condition->getRightOperand()))
				{
					$operatorValue = $condition->getOperator()->value;
					throw new InvalidFilterException("Operator \"$operatorValue\" cannot be used with array values.");
				}

				break;
		}
	}

	/**
	 * @throws InvalidFilterException
	 */
	private static function validateInCondition(Operator $operator, mixed $value): void
	{
		self::validateArrayValue($operator, $value);
		foreach ($value as $valueItem)
		{
			if ($valueItem !== null && !is_scalar($valueItem))
			{
				throw new InvalidFilterException("Operator \"$operator->value\" requires an array with scalar or null items.");
			}
		}
	}

	/**
	 * @throws InvalidFilterException
	 */
	private static function validateBetweenCondition(Condition $condition, Dto $dto): void
	{
		$operator = $condition->getOperator();
		$value = $condition->getRightOperand();
		self::validateArrayValue($operator, $value);
		if ($operator === Operator::Between && count($value) !== 2)
		{
			throw new InvalidFilterException("Operator \"$operator->value\" requires an array only with 2 items.");
		}

		$operandType = $dto->getFields()[$condition->getLeftOperand()]->getPropertyType();
		$field = $condition->getLeftOperand();

		if (!in_array($operandType, [DateTime::class, Date::class, 'int', 'float'], true))
		{
			throw new InvalidFilterException("You are can not use operator \"$operator->value\" for dto field \"$field\" with type $operandType.");
		}

		foreach ($value as $valueItem)
		{
			if (empty($valueItem))
			{
				throw new InvalidFilterException("Operator \"$operator->value\" should be used with compatible types for this field \"$field\" (int, float, Date, DateTime).");
			}
			if (preg_match('/^-?\d+(\.\d+)?$/', $valueItem)) // is_numeric
			{
				if (in_array($operandType, [Date::class, DateTime::class], true))
				{
					throw new InvalidFilterException("Operator \"$operator->value\" should be used with compatible types for this field \"$field\" (Date or DateTime).");
				}
			}
			else // Date or DateTime
			{
				if (in_array($operandType, ['int', 'float'], true))
				{
					throw new InvalidFilterException("Operator \"$operator->value\" should be used with compatible types for this field \"$field\" (int or float).");
				}

				if ($operandType === DateTime::class && !$valueItem instanceof DateTime)
				{
					throw new InvalidFilterException("Use correct DateTime format DATE_ATOM");
				}

				if ($operandType === Date::class && !$valueItem instanceof Date)
				{
					throw new InvalidFilterException("Use correct Date format YYYY-MM-DD");
				}
			}
		}
	}

	/**
	 * @throws InvalidFilterException
	 */
	private static function validateArrayValue(Operator $operator, mixed $value): void
	{
		if (!is_array($value))
		{
			throw new InvalidFilterException("Operator \"$operator->value\" requires an array value.");
		}
	}
}
