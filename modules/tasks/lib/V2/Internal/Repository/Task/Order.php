<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Task;

use Bitrix\Main\Text\StringHelper;

class Order
{
	public const ALLOWED_DIRECTIONS = [
		'asc',
		'desc',
	];

	public const ALLOWED_FIELDS = [
		'id',
		'title',
		'dateStart',
		'createdDate',
		'changedDate',
		'closedDate',
		'activityDate',
		'startDatePlan',
		'endDatePlan',
		'deadline',
		'status',
		'realStatus',
		'statusComplete',
		'priority',
		'mark',
		'originatorName',
		'createdBy',
		'createdByLastName',
		'responsibleName',
		'responsibleId',
		'responsibleLastName',
		'groupId',
		'computeGroupId',
		'timeEstimate',
		'allowChangeDeadline',
		'allowTimeTracking',
		'matchWorkTime',
		'sorting',
		'sortingOrder',
		'messageId',
		'favorite',
		'computeFavorite',
		'timeSpentInLogs',
		'isPinned',
		'isPinnedInGroup',
		'scrumItemsSort',
		'imChatId',
	];

	private array $fields;

	public function __construct(array $fields = [])
	{
		$filteredFields = array_intersect_key(
			$fields,
			array_flip(self::ALLOWED_FIELDS)
		);

		$filteredFields = array_filter(
			$filteredFields,
			fn (mixed $direction) => in_array($direction, self::ALLOWED_DIRECTIONS, true),
		);

		$this->fields = $filteredFields;
	}

	public function getFields() : array
	{
		return $this->fields;
	}

	public function prepareOrder(): array
	{
		$preparedOrder = [];

		foreach ($this->getFields() as $field => $direction)
		{
			$mappedField = $this->mapField($field);
			if ($mappedField !== null)
			{
				$preparedOrder[$mappedField] = strtoupper($direction);
			}
		}

		return $preparedOrder;
	}

	protected function mapField(string $field): ?string
	{
		$allowed = array_flip(self::ALLOWED_FIELDS);
		return match (true)
		{
			// here can be custom mappings
			$field === 'status' => 'REAL_STATUS',
			// default mapping
			isset($allowed[$field]) => strtoupper(StringHelper::camel2snake($field)),
			default => null,
		};
	}
}
