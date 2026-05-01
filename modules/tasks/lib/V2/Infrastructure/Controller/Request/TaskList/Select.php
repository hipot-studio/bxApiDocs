<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Request\TaskList;

use Bitrix\Main\Validation\Rule\Recursive\Validatable;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\FieldsEnum;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\TaskListSelect;

class Select
{
	protected function __construct(
		#[Validatable(iterable: true)]
		/** @var SelectItem[] */
		public readonly ?array $list = [],
	)
	{
	}

	public static function createFromArray(array $parameters): self
	{
		return new self(
			array_map(
				fn (string $parameter) => new SelectItem($parameter),
				$parameters,
			),
		);
	}

	public function prepare(): TaskListSelect
	{
		$selectList = [];
		foreach ($this->list as $field)
		{
			$mappedField = $this->mapField($field->field);
			if (null === $mappedField)
			{
				continue;
			}

			$selectList[$mappedField] = $mappedField;
		}

		return new TaskListSelect(array_values($selectList));
	}

	protected function mapField(string $field): ?string
	{
		/** @var FieldsEnum|null $mapped */
		$mapped = match ($field)
		{
			'id' => FieldsEnum::Id,
			'title' => FieldsEnum::Title,
			'status' => FieldsEnum::Status,
			'complete' => FieldsEnum::Status,
			'activityDate' => FieldsEnum::ActivityDate,
			'deadline' => FieldsEnum::Deadline,
			'creator' => FieldsEnum::CreatedBy,
			'responsible' => FieldsEnum::ResponsibleId,
			'group' => FieldsEnum::Group,
			'createdDate' => FieldsEnum::CreatedDate,
			'changedDate' => FieldsEnum::ChangedDate,
			'closedDate' => FieldsEnum::ClosedDate,
			'timeEstimate' => FieldsEnum::TimeEstimate,
			'allowTimeTracking' => FieldsEnum::AllowTimeTracking,
			'mark' => FieldsEnum::Mark,
			'allowChangeDeadline' => FieldsEnum::AllowChangeDeadline,
			'timeSpentInLogs' => FieldsEnum::TimeSpentInLogs,
			'startDatePlan' => FieldsEnum::StartDatePlan,
			'endDatePlan' => FieldsEnum::EndDatePlan,
			'flow' => FieldsEnum::Flow,
			'ufCrmTaskLead' => FieldsEnum::UfCrmTask,
			'ufCrmTaskContact' => FieldsEnum::UfCrmTask,
			'ufCrmTaskCompany' => FieldsEnum::UfCrmTask,
			'ufCrmTaskDeal' => FieldsEnum::UfCrmTask,
			'ufCrmTask' => FieldsEnum::UfCrmTask,
			'tags' => FieldsEnum::Tags,
			'links' => FieldsEnum::Links,
			default => null,
		};

		return $mapped?->value;
	}
}
