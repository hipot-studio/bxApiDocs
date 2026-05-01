<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Task;

use Bitrix\Main\Text\StringHelper;

class ListSelect
{
	public const ALLOWED_FIELDS = [
		'id',
		'title',
		'description',
		'descriptionChecksum',
		'declineReason',
		'priority',
		'statusComplete',
		'status',
		'realStatus',
		'multitask',
		'stageId',
		'stagesId',
		'responsible',
		'responsibleId',
		'responsibleName',
		'responsibleLastName',
		'responsibleSecondName',
		'responsibleLogin',
		'responsibleWorkPosition',
		'responsiblePhoto',
		'dateStart',
		'timeEstimate',
		'replicate',
		'deadline',
		'deadlineOrig',
		'startDatePlan',
		'endDatePlan',
		'creator',
		'createdBy',
		'createdByName',
		'createdByLastName',
		'createdBySecondName',
		'createdByLogin',
		'createdByWorkPosition',
		'createdByPhoto',
		'createdDate',
		'changedBy',
		'changedDate',
		'statusChangedBy',
		'closedBy',
		'closedDate',
		'activityDate',
		'guid',
		'xmlId',
		'mark',
		'allowChangeDeadline',
		'allowTimeTracking',
		'matchWorkTime',
		'taskControl',
		'addInReport',
		'group',
		'groupId',
		'groupName',
		'groupType',
		'forumTopicId',
		'parentId',
		'commentsCount',
		'serviceCommentsCount',
		'forumId',
		'siteId',
		'exchangeModified',
		'exchangeId',
		'outlookVersion',
		'viewedDate',
		'deadlineCounted',
		'forkedByTemplateId',
		'notViewed',
		'favorite',
		'sorting',
		'durationPlanSeconds',
		'durationTypeAll',
		'durationPlan',
		'durationType',
		'statusChangedDate',
		'timeSpentInLogs',
		'durationFact',
		'isMuted',
		'isPinned',
		'isPinnedInGroup',
		'subordinate',
		'count',
		'lengthDeadline',
		'scenarioName',
		'isRegular',
		'flowId',
		'flow',
		'chatId',
		'sprintId',
		'backlogId',
		'ufCrmTaskLead',
		'ufCrmTaskContact',
		'ufCrmTaskCompany',
		'ufCrmTaskDeal',
		'ufCrmTask',
		'tags',
		'links',
	];

	private readonly array $select;

	public function __construct(array $select)
	{
		$this->select = array_intersect($select, self::ALLOWED_FIELDS);
	}

	public function prepareSelect(): array
	{
		$selected = $this->select;
		$preparedSelect = [];

		foreach ($selected as $field)
		{
			$mappedField = $this->mapField($field);

			if ($mappedField !== null)
			{
				$preparedSelect[$field] = $mappedField;
			}
		}

		return array_values($preparedSelect);
	}

	public function hasCrmFields(): array
	{
		return array_intersect(
			$this->select,
			[
				'ufCrmTaskLead',
				'ufCrmTaskContact',
				'ufCrmTaskCompany',
				'ufCrmTaskDeal',
				'ufCrmTask',
			]
		);
	}

	public function has($field): bool
	{
		return isset(array_flip($this->select)[$field]);
	}

	protected function mapField(string $field): ?string
	{
		$allowed = array_flip(self::ALLOWED_FIELDS);
		return match (true)
		{
			// here can be custom mappings
			$field === 'status',
				$field === 'statusComplete' => 'REAL_STATUS',
			$field === 'descriptionChecksum' => 'DESCRIPTION',
			$field === 'group' => 'GROUP_ID',
			$field === 'creator' => 'CREATED_BY',
			$field === 'responsible' => 'RESPONSIBLE_ID',
			$field === 'ufCrmTaskLead',
				$field === 'ufCrmTaskContact',
				$field === 'ufCrmTaskCompany',
				$field === 'ufCrmTaskDeal',
				$field === 'ufCrmTask' => 'UF_CRM_TASK',
			// default mapping
			isset($allowed[$field]) => strtoupper(StringHelper::camel2snake($field)),
			default => null,
		};
	}
}
