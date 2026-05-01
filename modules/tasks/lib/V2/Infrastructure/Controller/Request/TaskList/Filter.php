<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Request\TaskList;

use Bitrix\Main\Validation\Rule\Recursive\Validatable;
use Bitrix\Main\Validation\ValidationError;
use Bitrix\Main\Validation\ValidationException;
use Bitrix\Tasks\Util\Type\DateTime;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\FieldsEnum;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\TaskListArrayFilter;

/**
 * For now filter supported only plain list of AND conditions without subfilters
 */
class Filter
{
	protected function __construct(
		#[Validatable(iterable: true)]
		/** @var FilterItem[] */
		public readonly ?array $list = [],
	)
	{
	}

	public static function createFromArray(array $parameters): self
	{
		$preparedParameters = array_map(
			static fn ($condition) => new FilterItem(...$condition),
			$parameters,
		);

		return new self($preparedParameters);
	}

	/**
	 * @throws ValidationException
	 */
	public function prepare(): TaskListArrayFilter
	{
		$preparedList = [];

		foreach ($this->list ?? [] as $item)
		{
			$mappedField = $this->mapField($item->field);
			if ($mappedField === null)
			{
				throw new ValidationException([
					new ValidationError(sprintf('Unknown field %s', $item->field))
				]);
			}

			$mappedOperator = $this->mapOperator($item->operator);
			if ($mappedOperator === null)
			{
				throw new ValidationException([
					new ValidationError(sprintf('Unknown operator %s', $item->field))
				]);
			}

			$preparedValue = $item->value;
			if ($this->isDateField($item->field))
			{
				$preparedValue = match (true)
				{
					is_array($item->value) => array_map($this->prepareDateValue(...), $item->value),
					default => $this->prepareDateValue($item->value),
				};
			}

			if (
				$preparedValue === null
				|| (is_array($preparedValue) && in_array(null, $preparedValue, true))
			)
			{
				throw new ValidationException([
					new ValidationError(sprintf('Field `%s` data is invalid', $item->field))
				]);
			}

			$preparedList[] = [
				$mappedField,
				$mappedOperator,
				$preparedValue,
			];
		}

		return new TaskListArrayFilter($preparedList);
	}

	private function isDateField(string $field): bool
	{
		return in_array($field, FilterItem::DATE_FIELDS, true);
	}

	private function prepareDateValue(mixed $value): ?DateTime
	{
		if (!ctype_digit($value))
		{
			return null;
		}

		return DateTime::createFromTimestamp((int)$value);
	}

	protected function mapField(string $field): ?string
	{
		/** @var FieldsEnum $mappedField */
		$mappedField = match ($field)
		{
			'id' => FieldsEnum::Id,
			'title' => FieldsEnum::Title,
			'activityDate' => FieldsEnum::ActivityDate,
			'deadline' => FieldsEnum::Deadline,
			'createdDate' => FieldsEnum::CreatedDate,
			'closedDate' => FieldsEnum::ClosedDate,
			'startDatePlan' => FieldsEnum::StartDatePlan,
			'endDatePlan' => FieldsEnum::EndDatePlan,
			'dateStart' => FieldsEnum::DateStart,
			'status' => FieldsEnum::RealStatus,
			'allowTimeTracking' => FieldsEnum::AllowTimeTracking,
			'mark' => FieldsEnum::Mark,
			'priority' => FieldsEnum::Priority,
			'createdBy' => FieldsEnum::CreatedBy,
			'responsibleId' => FieldsEnum::ResponsibleId,
			'groupId' => FieldsEnum::GroupId,
			'flow' => FieldsEnum::FlowId,
			'accomplice' => FieldsEnum::Accomplice,
			'auditor' => FieldsEnum::Auditor,
			'tag' => FieldsEnum::Tag,
			'active' => FieldsEnum::Active,
			'addInReport' => FieldsEnum::AddInReport,
			'overdued' => FieldsEnum::Overdued,
			'favorite' => FieldsEnum::Favorite,
			'notViewed' => FieldsEnum::NotViewed,
			'viewed' => FieldsEnum::Viewed,
			'isMuted' => FieldsEnum::IsMuted,
			'mentioned' => FieldsEnum::Mentioned,
			'withNewComments' => FieldsEnum::WithNewComments,
			'member' => FieldsEnum::Member,
			'commentSearchIndex' => FieldsEnum::CommentSearchIndex,
			default => null,
		};

		if (!in_array($mappedField, FieldsEnum::allowedForFilterList(), true))
		{
			return null;
		}

		return $mappedField?->value;
	}

	protected function mapOperator(string $operator): ?string
	{
		$allowed = array_flip(FilterItem::ALLOWED_OPERATORS);

		return isset($allowed[$operator]) ? $operator : null;
	}
}
