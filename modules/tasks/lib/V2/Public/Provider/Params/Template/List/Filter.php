<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Template\List;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Validation\ValidationError;
use Bitrix\Main\Validation\Validator\ValidatorInterface;
use Bitrix\Tasks\V2\Internal\Repository\Template\List;
use Bitrix\Tasks\V2\Public\Provider\Params\FilterOperator;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\Field;
use Bitrix\Tasks\V2\Public\Provider\Params\Validation\Rule\Template\List\AllowedWithOperator;
use Bitrix\Tasks\V2\Public\Provider\Params\Validation\Rule\Template\List\NotAllowedWithOperator;
use Bitrix\Tasks\V2\Public\Provider\Params\Validation\Rule\Template\List\ValueType;

class Filter
{
	private array $filter = [];

	/**
	 * @throws ArgumentException
	 */
	public function addFilter(FilterItem $item, bool $throwOnError = false): self
	{
		if (!Field::allowedForFilter($item->field))
		{
			if ($throwOnError)
			{
				throw new ArgumentException(sprintf('Field "%s" is not allowed for filter', $item->field->value));
			}
		}

		if ($this->isValid($item, $throwOnError))
		{
			$this->filter[] = $item;
		}

		return $this;
	}

	/**
	 * @throws ArgumentException
	 */
	public static function createFromArray(array $filter, bool $throwOnError = false): static
	{
		$instance = new static();
		foreach ($filter as [$field, $operator, $value])
		{
			$enumField = $field instanceof Field ? $field : Field::tryFrom($field);
			$enumOperator = $operator instanceof FilterOperator ? $operator : FilterOperator::tryFrom($operator);
			if ($enumField === null || $enumOperator === null)
			{
				if ($throwOnError)
				{
					throw new ArgumentException('Invalid field or operator in filter');
				}
				else
				{
					continue;
				}
			}

			$instance->addFilter(new FilterItem($enumField, $enumOperator, $value), $throwOnError);
		}

		return $instance;
	}

	/**
	 * @return FilterItem[]
	 */
	public function getFilter(): array
	{
		return $this->filter;
	}

	public function mapToRepository(): List\Filter
	{
		$map = Field::getDefaultMapToRepositoryField();
		$operatorMap = FilterOperator::getDefaultMapToRepositoryOperator();

		$list = array_map(
			fn (FilterItem $item) => [
				$map[$item->field->value],
				$operatorMap[$item->operator->value],
				$item->value,
			],
			$this->getFilter()
		);

		return new List\Filter($list);
	}

	/**
	 * @throws ArgumentException
	 */
	protected function isValid(mixed $item, bool $throwOnError = false): bool
	{
		$validationErrors = $this->validate($item);
		if (count($validationErrors) > 0)
		{
			if ($throwOnError)
			{
				$errors = array_map(
					fn(ValidationError $error) => $error->getMessage(),
					$validationErrors
				);

				throw new ArgumentException(implode(';', $errors));
			}

			return false;
		}

		return true;
	}

	/**
	 * @return ValidationError[]
	 */
	protected function validate(mixed $value): array
	{
		return array_merge(
			...array_map(
				fn(ValidatorInterface $rule) => $rule->validate($value)->getErrors(),
				$this->getRules($value),
			)
		);
	}

	protected function getRules(FilterItem $item): array
	{
		return match ($item->field)
		{
			Field::Id,
			Field::CreatedBy,
			Field::ResponsibleId,
			Field::TaskId,
			Field::GroupId,
			Field::XmlId,
			Field::BaseTemplateId,
			Field::StageId => [
				new ValueType('integer'),
				new NotAllowedWithOperator([FilterOperator::Like]),
			],
			Field::AccessUserId => [
				new ValueType('integer'),
				new AllowedWithOperator([FilterOperator::Equal]),
			],
			Field::Zombie,
			Field::Replicate => [
				new ValueType('boolean'),
				new AllowedWithOperator([FilterOperator::Equal]),
			],
			Field::Priority,
			Field::TParamType,
			Field::Scenario,
			Field::Title => [
				new ValueType('string'),
				new AllowedWithOperator([FilterOperator::Equal, FilterOperator::NotEqual, FilterOperator::Like, FilterOperator::In]),
			],
			Field::SearchIndex => [
				new ValueType('string'),
				new AllowedWithOperator([FilterOperator::Equal]),
			],
			default => [],
		};
	}
}