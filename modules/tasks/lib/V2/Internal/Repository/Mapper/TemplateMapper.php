<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper;

use Bitrix\Tasks\Internals\Task\Template\TemplateObject;
use Bitrix\Tasks\V2\Internal\Entity\Group;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;
use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Template\ReplicateParamsMapper;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Template\SubTemplateMapper;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Template\TemplateTagMapper;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Template\TypeMapper;

class TemplateMapper
{
	public function __construct(
		private readonly PriorityMapper $priorityMapper,
		private readonly UserFieldMapper $userFieldMapper,
		private readonly ReplicateParamsMapper $replicateParamsMapper,
		private readonly TypeMapper $typeMapper,
		private readonly TemplateTagMapper $tagMapper,
		private readonly SubTemplateMapper $subTemplateMapper,
	)
	{

	}

	public function mapFromTemplateObject(
		TemplateObject $templateObject,
		?Template\TagCollection $tags = null,
		?Group $group = null,
		?UserCollection $members = null,
		?array $crmItemIds = null,
		?array $checkListIds = null,
		?array $aggregates = null,
		?Template\PermissionCollection $permissions = null,
		?array $fileIds = null,
		null|Task|Template $parent = null,
		?array $params = null,
	): Template
	{
		return new Template(
			id: $templateObject->getId(),
			title: $templateObject->getTitle(),
			description: $templateObject->getDescription(),
			creator: $members?->findOneById($templateObject->getCreatedBy()),
			responsibleCollection: $members?->findAllByIds((array)$templateObject->getMembers()?->getResponsibleIds()),
			deadlineAfter: $templateObject->getDeadlineAfter(),
			startDatePlanAfter: $templateObject->getStartDatePlanAfter(),
			endDatePlanAfter: $templateObject->getEndDatePlanAfter(),
			allowsChangeDeadline: $templateObject->getAllowChangeDeadline(),
			allowsTimeTracking: $templateObject->getAllowTimeTracking(),
			matchesWorkTime: $templateObject->getMatchWorkTime(),
			needsControl: $templateObject->getTaskControl(),
			replicate: $templateObject->getReplicate(),
			replicateParams: $this->replicateParamsMapper->mapToValueObject($templateObject->getReplicateParams()),
			replicateCount: $templateObject->getTparamReplicationCount(),
			group: $group,
			estimatedTime: $templateObject->getTimeEstimate(),
			tags: $tags,
			parent: $parent instanceof Task ? $parent : null,
			base: $parent instanceof Template ? $parent : null,
			type: $this->typeMapper->mapToEnum((int)$templateObject->getTparamType()),
			fileIds: $fileIds,
			checklist: $checkListIds,
			priority: $this->priorityMapper->mapToEnum((int)$templateObject->getPriority()),
			accomplices: $members?->findAllByIds((array)$templateObject->getMembers()?->getAccompliceIds()),
			auditors: $members?->findAllByIds((array)$templateObject->getMembers()?->getAuditorIds()),
			siteId: $templateObject->getSiteId(),
			permissions: $permissions,
			userFields: $this->userFieldMapper->mapToCollection($templateObject->collectValues()),
			crmItemIds: $crmItemIds,
			containsRelatedTasks: $aggregates['containsRelatedTasks'] ?? null,
			containsChecklist: $aggregates['containsCheckList'] ?? null,
			containsSubTemplates: $aggregates['containsSubTemplates'] ?? null,
			groupId: $templateObject->getGroupId(),
			requireResult: $params['requireResult'] ?? null,
		);
	}

	public function mapListItem(
		TemplateObject $templateObject,
		?UserCollection $members = null,
	): Template
	{
		$values = $templateObject->collectValues();
		$creatorId = $templateObject->getMembers()?->getOriginatorId();

		return new Template(
			id: $templateObject->getId(),
			title: $templateObject->getTitle(),
			description: $templateObject->getDescription(),
			creator: $creatorId === null ? null : $members->findOneById($creatorId),
			responsibleCollection: $members?->findAllByIds((array)$templateObject->getMembers()?->getResponsibleIds()),
			deadlineAfter: $templateObject->getDeadlineAfter(),
			startDatePlanAfter: $templateObject->getStartDatePlanAfter(),
			endDatePlanAfter: $templateObject->getEndDatePlanAfter(),
			allowsChangeDeadline: $templateObject->getAllowChangeDeadline(),
			allowsTimeTracking: $templateObject->getAllowTimeTracking(),
			matchesWorkTime: $templateObject->getMatchWorkTime(),
			needsControl: $templateObject->getTaskControl(),
			replicate: $templateObject->getReplicate(),
			replicateParams: $this->replicateParamsMapper->mapToValueObject($templateObject->getReplicateParams()),
			estimatedTime: $templateObject->getTimeEstimate(),
			dependsOn: is_array($templateObject->getDependsOn()) ? $templateObject->getDependsOn() : null,
			tags: $this->tagMapper->mapToCollection($templateObject->getTags()),
			type: $this->typeMapper->mapToEnum($templateObject->getTparamType()),
			fileIds: $templateObject->get(UserField::TASK_ATTACHMENTS),
			priority: $this->priorityMapper->mapToEnum((int)$templateObject->getPriority()),
			accomplices: $members?->findAllByIds((array)$templateObject->getMembers()?->getAccompliceIds()),
			auditors: $members?->findAllByIds((array)$templateObject->getMembers()?->getAuditorIds()),
			siteId: $templateObject->getSiteId(),
			userFields: $this->userFieldMapper->mapToCollection($values),
			containsSubTemplates: count($templateObject->getSubTemplatesArray()) > 0,
			multitask: $templateObject->getMultitask(),
			baseTemplateId: $templateObject->getBaseTemplateId(),
			groupId: $templateObject->getGroupId(),
			subTemplates: $this->subTemplateMapper->mapToCollection($templateObject->getSubTemplatesArray()),
			status: $templateObject->getStatus(),
			xmlId: $templateObject->getXmlId(),
			addInReport: $templateObject->getAddInReport(),
			replicationCount: $templateObject->getTparamReplicationCount(),
			templateChildrenCount: $templateObject->getDirectChildrenCount(),
			scenario: $templateObject->getScenario()?->getScenario(),
			taskId: $templateObject->getTaskId(),
			parentId: $templateObject->getParentId(),
			descriptionInBbcode: $templateObject->getDescriptionInBbcode(),
			stageId: $templateObject->getStageId(),
		);
	}
}
