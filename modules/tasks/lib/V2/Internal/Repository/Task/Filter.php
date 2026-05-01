<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Task;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Query\Filter\Condition;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\SystemException;
use Bitrix\Main\Text\StringHelper;

class Filter
{
	public const ALLOWED_FIELDS = [
		'parentId',
		'groupId',
		'statusChangedBy',
		'forumTopicId',
		'id',
		'priority',
		'createdBy',
		'responsibleId',
		'stageId',
		'timeEstimate',
		'forkedByTemplateId',
		'deadlineCounted',
		'changedBy',
		'guid',
		'title',
		'fullSearchIndex',
		'commentSearchIndex',
		'tag',
		'tagId',
		'flow',
		'flowId',
		'sprintId',
		'backlogId',
		'realStatus',
		'viewed',
		'statusExpired',
		'statusNew',
		'status',
		'mark',
		'xmlId',
		'siteId',
		'addInReport',
		'allowTimeTracking',
		'allowChangeDeadline',
		'matchWorkTime',
		'isRegular',
		'endDatePlan',
		'startDatePlan',
		'dateStart',
		'deadline',
		'createdDate',
		'closedDate',
		'changedDate',
		'activityDate',
		'accomplice',
		'auditor',
		'period',
		'active',
		'doer',
		'member',
		'dependsOn',
		'dependsOnTemplate',
		'ganttAncestorId',
		'onlyRootTasks',
		'subordinateTasks',
		'overdued',
		'sameGroupParent',
		'sameGroupParentEx',
		'departmentId',
		'checkPermissions',
		'favorite',
		'sorting',
		'stagesId',
		'projectNewComments',
		'projectExpired',
		'mentioned',
		'withCommentCounters',
		'withNewComments',
		'withNewCommentsForum',
		'isMuted',
		'isPinned',
		'isPinnedInGroup',
		'scrumTasks',
		'storyPoints',
		'epic',
		'scenarioName',
		'imChatId',
		'imChatChatId',
	];

	public function __construct(
		private readonly ?ConditionTree $filter,
		public readonly ?int $userId = null,
		public readonly bool $skipAccessCheck = false,
	)
	{
	}

	/**
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function prepareFilter(): ConditionTree
	{
		return $this->mapFilterConditions($this->filter ?? new ConditionTree());
	}

	/**
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function prepareArrayFilter(): array
	{
		return $this->mapFilterConditionsToArray($this->filter ?? new ConditionTree());
	}

	/**
	 * @throws ArgumentException
	 */
	protected function mapFilterConditionsToArray(ConditionTree $filter): array
	{
		$result = [];
		$result['::LOGIC'] = strtoupper($filter->logic());

		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof Condition)
			{
				$mappedField = $this->mapField($condition->getColumn());
				$mappedOperator = $this->mapOperator($condition->getOperator());
				if ($mappedField === null || $mappedOperator === null)
				{
					continue;
				}

				$key = sprintf(
					'%s%s',
					$mappedOperator,
					$mappedField,
				);

				$result[$key] = $condition->getValue();
			}
			else if ($condition instanceof ConditionTree)
			{
				$result[] = $this->mapFilterConditionsToArray($condition);
			}
		}

		return $result;
	}

	/**
	 * @throws ArgumentException
	 */
	protected function mapFilterConditions(ConditionTree $filter): ConditionTree
	{
		$result = new ConditionTree();
		$result->logic($filter->logic());

		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof Condition)
			{
				$mappedCondition = new Condition(
					$this->mapField($condition->getColumn()),
					$condition->getOperator(),
					$condition->getValue(),
				);

				$result->where($mappedCondition);
			}
			else if ($condition instanceof ConditionTree)
			{
				$result->where($this->mapFilterConditions($condition));
			}
		}

		return $result;
	}

	protected function mapField(string $field): ?string
	{
		$allowed = array_flip(self::ALLOWED_FIELDS);
		return match (true)
		{
			// here can be custom mappings like someField => CUSTOM_FIELD
			// default mapping someField => SOME_FIELD
			isset($allowed[$field]) => strtoupper(StringHelper::camel2snake($field)),
			default => null,
		};
	}

	protected function mapOperator(string $operator): ?string
	{
		$map = [
			// special cases
			'in' => '@',
			'like' => '%',
		];

		return $map[$operator] ?? $operator;
	}
}
