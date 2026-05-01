<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Mapping;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\Order;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\FieldsEnum;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\TaskListParams;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\TaskListRestFilter;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\TaskListSelect;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\TaskListSort;

class TaskListRequestMapper
{
	protected static function mapSelect(?SelectStructure $select): TaskListSelect
	{
		$map = [
			'id' => FieldsEnum::Id,
			'title' => FieldsEnum::Title,
			'description' => FieldsEnum::Description,
			'creatorId' => FieldsEnum::CreatedBy,
			'creator' => FieldsEnum::CreatedBy,
			'created' => FieldsEnum::CreatedDate,
			'responsibleId' => FieldsEnum::ResponsibleId,
			'responsible' => FieldsEnum::ResponsibleId,
			'deadline' => FieldsEnum::Deadline,
			'needsControl' => FieldsEnum::TaskControl,
			'startPlan' => FieldsEnum::StartDatePlan,
			'endPlan' => FieldsEnum::EndDatePlan,
			'groupId' => FieldsEnum::GroupId,
			'group' => FieldsEnum::GroupId,
			'stageId' => FieldsEnum::StageId,
			'flowId' => FieldsEnum::FlowId,
			'flow' => FieldsEnum::Flow,
			'priority' => FieldsEnum::Priority,
			'status' => FieldsEnum::Status,
			'statusChanged' => FieldsEnum::StatusChangedDate,
			'parentId' => FieldsEnum::ParentId,
			'chatId' => FieldsEnum::ChatId,
			'plannedDuration' => FieldsEnum::DurationPlan,
			'actualDuration' => FieldsEnum::DurationFact,
			'durationType' => FieldsEnum::DurationType,
			'started' => FieldsEnum::DateStart,
			'estimatedTime' => FieldsEnum::TimeEstimate,
			'replicate' => FieldsEnum::Replicate,
			'changed' => FieldsEnum::ChangedDate,
			'changedById' => FieldsEnum::ChangedBy,
			'changedBy' => FieldsEnum::ChangedBy,
			'statusChangedById' => FieldsEnum::StatusChangedBy,
			'statusChangedBy' => FieldsEnum::StatusChangedBy,
			'closedById' => FieldsEnum::ClosedBy,
			'closed' => FieldsEnum::ClosedDate,
			'activity' => FieldsEnum::ActivityDate,
			'guid' => FieldsEnum::Guid,
			'xmlId' => FieldsEnum::XmlId,
			'exchangeId' => FieldsEnum::ExchangeId,
			'exchangeModified' => FieldsEnum::ExchangeModified,
			'outlookVersion' => FieldsEnum::OutlookVersion,
			'mark' => FieldsEnum::Mark,
			'allowsChangeDeadline' => FieldsEnum::AllowChangeDeadline,
			'allowsTimeTracking' => FieldsEnum::AllowTimeTracking,
			'matchesWorkTime' => FieldsEnum::MatchWorkTime,
			'addInReport' => FieldsEnum::AddInReport,
			'isMultitask' => FieldsEnum::Multitask,
			'siteId' => FieldsEnum::SiteId,
			'forkedByTemplateId' => FieldsEnum::ForkedByTemplateId,
			'deadlineCount' => FieldsEnum::DeadlineCounted,
			'declineReason' => FieldsEnum::DeclineReason,
			'forumTopicId' => FieldsEnum::ForumTopicId,
			'elapsedTime' => FieldsEnum::TimeSpentInLogs,
			'tags' => FieldsEnum::Tags,
			'crmItems' => FieldsEnum::UfCrmTask,
			'crmItemIds' => FieldsEnum::UfCrmTask,
		];

		$mappedSelect = [];
		$selected = array_merge($select?->getList() ?? [], $select?->getRelationFields() ?? []);
		foreach ($selected as $item)
		{
			/** @var FieldsEnum $field */
			$field = $map[$item] ?? null;
			if ($field === null)
			{
				continue;
			}

			$mappedSelect[] = $map[$item]->value;
		}

		return new TaskListSelect(array_unique($mappedSelect));
	}

	protected static function mapOrder(?OrderStructure $order): TaskListSort
	{
		$map = [
			'id' => FieldsEnum::Id,
			'title' => FieldsEnum::Title,
			'creatorId' => FieldsEnum::CreatedBy,
			'created' => FieldsEnum::CreatedDate,
			'responsibleId' => FieldsEnum::ResponsibleId,
			'deadline' => FieldsEnum::Deadline,
			'startPlan' => FieldsEnum::StartDatePlan,
			'endPlan' => FieldsEnum::EndDatePlan,
			'groupId' => FieldsEnum::GroupId,
			'priority' => FieldsEnum::Priority,
			'status' => FieldsEnum::RealStatus,
			'started' => FieldsEnum::DateStart,
			'estimatedTime' => FieldsEnum::TimeEstimate,
			'changed' => FieldsEnum::ChangedDate,
			'closed' => FieldsEnum::ClosedDate,
			'activity' => FieldsEnum::ActivityDate,
			'mark' => FieldsEnum::Mark,
			'allowsChangeDeadline' => FieldsEnum::AllowChangeDeadline,
			'allowsTimeTracking' => FieldsEnum::AllowTimeTracking,
		];

		$mappedSort = [
			FieldsEnum::Id->value => Order::Asc->value,
		];

		foreach ($order?->getItems() ?? [] as $item)
		{
			/** @var FieldsEnum $mappedField */
			$mappedField = $map[$item->getProperty()] ?? null;
			if ($mappedField === null)
			{
				continue;
			}

			$mappedSort[$mappedField->value] = strtolower($item->getOrder()->value);
		}

		return new TaskListSort($mappedSort);
	}

	protected static function mapFilter(?FilterStructure $filter): TaskListRestFilter
	{
		// сейчас только одно поле TaskDto имеет тип Filterable: id - маппинга не требует
		return new TaskListRestFilter($filter);
	}

	/**
	 * @throws ArgumentException
	 */
	public static function mapToListParams(ListRequest $request, int $userId, bool $skipAccessCheck): TaskListParams
	{
		if ($request->pagination !== null)
		{
			$pagination = new Pager($request->pagination->getLimit(), $request->pagination->getOffset());
		}
		else
		{
			$pagination = new Pager();
		}

		$select = self::mapSelect($request->select);
		$order = self::mapOrder($request->order);
		$filter = self::mapFilter($request->filter);

		return new TaskListParams(
			userId: $userId,
			pagination: $pagination,
			filter: $filter,
			sort: $order,
			select: $select,
			skipAccessCheck: $skipAccessCheck,
		);
	}
}
