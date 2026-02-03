<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper\Template;

use Bitrix\Tasks\Internals\Task\Template\TemplateObject;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\PriorityMapper;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Trait\CastTrait;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\UserFieldMapper;
use Bitrix\Tasks\Validation\Validator\SerializedValidator;

class OrmTemplateMapper
{
	use CastTrait;

	public function __construct(
		private readonly PriorityMapper $priorityMapper,
		private readonly TemplatePermissionMapper $templatePermissionMapper,
		private readonly ReplicateParamsMapper $replicateParamsMapper,
		private readonly UserFieldMapper $userFieldMapper,
		private readonly TypeMapper $typeMapper,
	)
	{

	}

	public function mapToObject(Entity\Template $template): TemplateObject
	{
		$fields = $this->mapFromEntity($template);

		return TemplateObject::wakeUpObject($fields);
	}

	public function mapFromEntity(Entity\Template $template, bool $forSave = false): array
	{
		$fields = [];
		if ($template->id)
		{
			$fields['ID'] = $template->id;
		}
		else
		{
			$fields['DESCRIPTION_IN_BBCODE'] = true; // true for new templates, ignore for existing template
		}

		if ($template->title !== null && $template->title !== '')
		{
			$fields['TITLE'] = $template->title;
		}

		if ($template->description || $template->description === '')
		{
			$fields['DESCRIPTION'] = $template->description;
		}

		if ($template->creator?->id)
		{
			$fields['CREATED_BY'] = $template->creator->id;
		}

		if ($template->responsibleCollection !== null)
		{
			if ($forSave)
			{
				$fields['RESPONSIBLE_ID'] = $template->responsibleCollection->getFirstEntity()?->getId();
			}
			else
			{
				$fields['RESPONSIBLES'] = $template->responsibleCollection->getIdList();
			}
		}

		if ($template->deadlineAfter !== null)
		{
			$fields['DEADLINE_AFTER'] = $template->deadlineAfter;
		}

		if ($template->startDatePlanAfter !== null)
		{
			$fields['START_DATE_PLAN_AFTER'] = $template->startDatePlanAfter;
		}

		if ($template->endDatePlanAfter !== null)
		{
			$fields['END_DATE_PLAN_AFTER'] = $template->endDatePlanAfter;
		}

		if ($template->allowsChangeDeadline !== null)
		{
			$fields['ALLOW_CHANGE_DEADLINE'] = $template->allowsChangeDeadline;
		}

		if ($template->matchesWorkTime !== null)
		{
			$fields['MATCH_WORK_TIME'] = $template->matchesWorkTime;
		}

		if ($template->needsControl !== null)
		{
			$fields['TASK_CONTROL'] = $template->needsControl;
		}

		if ($template->replicate !== null)
		{
			$fields['REPLICATE'] = $template->replicate;
		}

		if ($template->replicateParams !== null)
		{
			$fields['REPLICATE_PARAMS'] = $this->replicateParamsMapper->mapFromValueObject($template->replicateParams);
			if ($forSave)
			{
				$fields['REPLICATE_PARAMS'] = serialize($fields['REPLICATE_PARAMS']);
			}
		}

		if ($template->group?->id !== null)
		{
			$fields['GROUP_ID'] = $template->group->id;
		}

		if ($template->estimatedTime !== null)
		{
			$fields['TIME_ESTIMATE'] = $template->estimatedTime;
		}

		if ($template->dependsOn !== null)
		{
			$fields['DEPENDS_ON'] = $template->dependsOn;
		}

		if ($template->tags !== null)
		{
			$fields['TAGS'] = $template->tags->getNameList();
		}

		if ($template->parent?->id !== null)
		{
			$fields['PARENT_ID'] = $template->parent->id;
		}

		if ($template->base?->id !== null)
		{
			$fields['BASE_TEMPLATE_ID'] = $template->base->id;
		}

		if ($template->type !== null)
		{
			$fields['TPARAM_TYPE'] = $this->typeMapper->mapFromEnum($template->type);
		}

		if ($template->priority)
		{
			$fields['PRIORITY'] = $this->priorityMapper->mapFromEnum($template->priority);
		}

		if ($template->siteId)
		{
			$fields['SITE_ID'] = $template->siteId;
		}

		if ($template->task?->id)
		{
			$fields['TASK_ID'] = $template->task->id;
		}

		if ($template->accomplices !== null)
		{
			$fields['ACCOMPLICES'] = $template->accomplices->getIdList();
		}

		if ($template->auditors !== null)
		{
			$fields['AUDITORS'] = $template->auditors->getIdList();
		}

		if ($template->permissions !== null)
		{
			$fields['PERMISSIONS'] = $this->templatePermissionMapper->mapFromCollection($template->permissions);
		}

		if ($template->fileIds !== null)
		{
			$fields[Entity\UF\UserField::TASK_ATTACHMENTS] = $template->fileIds;
		}

		if ($template->allowsTimeTracking !== null)
		{
			$fields['ALLOW_TIME_TRACKING'] = $template->allowsTimeTracking;
		}

		if ($template->userFields)
		{
			foreach ($template->userFields as $userField)
			{
				$fields[$userField->key] = $userField->value;
			}
		}

		if ($template->crmItemIds !== null)
		{
			$fields[Entity\UF\UserField::TASK_CRM] = $template->crmItemIds;
		}

		if ($template->multitask !== null)
		{
			$fields['MULTITASK'] = $template->multitask ? 'Y' : 'N';
		}

		return $fields;
	}

	public function mapToEntity(array $fields): Entity\Template
	{
		$templateFields = ['id' => null];

		if (isset($fields['ID']))
		{
			$templateFields['id'] = $fields['ID'];
		}

		if (isset($fields['TITLE']))
		{
			$templateFields['title'] = $fields['TITLE'];
		}

		if (isset($fields['DESCRIPTION']))
		{
			$templateFields['description'] = $fields['DESCRIPTION'];
		}

		if (isset($fields['CREATED_BY']))
		{
			$templateFields['creator'] = $this->castMember((int)$fields['CREATED_BY']);
		}

		if (isset($fields['RESPONSIBLES']) && is_array($fields['RESPONSIBLES']))
		{
			$templateFields['responsibleCollection'] = $this->castMembers($fields['RESPONSIBLES']);
		}
		elseif (isset($fields['RESPONSIBLE_ID']))
		{
			$templateFields['responsibleCollection'] = $this->castMembers([$fields['RESPONSIBLE_ID']]);
		}

		if (isset($fields['MULTITASK']))
		{
			$templateFields['multitask'] = $fields['MULTITASK'] === 'Y';
		}

		if (isset($fields['DEADLINE_AFTER']))
		{
			$templateFields['deadlineAfter'] = $fields['DEADLINE_AFTER'];
		}

		if (isset($fields['START_DATE_PLAN_AFTER']))
		{
			$templateFields['startDatePlanAfter'] = $fields['START_DATE_PLAN_AFTER'];
		}

		if (isset($fields['END_DATE_PLAN_AFTER']))
		{
			$templateFields['endDatePlanAfter'] = $fields['END_DATE_PLAN_AFTER'];
		}

		if (isset($fields['ALLOW_CHANGE_DEADLINE']))
		{
			$templateFields['allowsChangeDeadline'] = $fields['ALLOW_CHANGE_DEADLINE'] === 'Y'
				|| $fields['ALLOW_CHANGE_DEADLINE'] === true;
		}

		if (isset($fields['ALLOW_TIME_TRACKING']))
		{
			$templateFields['allowsTimeTracking'] = $fields['ALLOW_TIME_TRACKING'] === 'Y'
				|| $fields['ALLOW_TIME_TRACKING'] === true;
		}

		if (isset($fields['MATCH_WORK_TIME']))
		{
			$templateFields['matchesWorkTime'] = $fields['MATCH_WORK_TIME'] === 'Y'
				|| $fields['MATCH_WORK_TIME']
				=== true;
		}

		if (isset($fields['TASK_CONTROL']))
		{
			$templateFields['needsControl'] = $fields['TASK_CONTROL'] === 'Y' || $fields['TASK_CONTROL'] === true;
		}

		if (isset($fields['REPLICATE']))
		{
			$templateFields['replicate'] = $fields['REPLICATE'] === 'Y' || $fields['REPLICATE'] === true;
		}

		if (isset($fields['TIME_ESTIMATE']))
		{
			$templateFields['estimatedTime'] = $fields['TIME_ESTIMATE'];
		}

		if (isset($fields['ACCOMPLICES']) && is_array($fields['ACCOMPLICES']))
		{
			$templateFields['accomplices'] = $this->castMembers($fields['ACCOMPLICES']);
		}

		if (isset($fields['AUDITORS']) && is_array($fields['AUDITORS']))
		{
			$templateFields['auditors'] = $this->castMembers($fields['AUDITORS']);
		}

		if (isset($fields['PARENT_ID']))
		{
			$templateFields['parent'] = ['id' => (int)$fields['PARENT_ID']];
		}

		if (isset($fields['BASE_TEMPLATE_ID']))
		{
			$templateFields['base'] = ['id' => (int)$fields['BASE_TEMPLATE_ID']];
		}

		if (isset($fields['TPARAM_TYPE']))
		{
			$templateFields['type'] = $this->typeMapper->mapToEnum((int)$fields['TPARAM_TYPE']);
		}

		if (isset($fields['PRIORITY']) && is_numeric($fields['PRIORITY']))
		{
			$templateFields['priority'] = $this->priorityMapper->mapToEnum((int)$fields['PRIORITY'])->value;
		}

		if (isset($fields['SITE_ID']))
		{
			$templateFields['siteId'] = $fields['SITE_ID'];
		}

		if (isset($fields['PERMISSIONS']))
		{
			$templateFields['permissions'] = $this->templatePermissionMapper->mapToCollection(
				(array)$fields['PERMISSIONS'], (int)$templateFields['id']
			);
		}

		if (isset($fields['GROUP_ID']))
		{
			$templateFields['groupId'] = (int)$fields['GROUP_ID'];
		}

		if (isset($fields['TAGS']) && is_array($fields['TAGS']))
		{
			$templateFields['tags'] = $fields['TAGS'];
		}
		elseif (isset($fields['SE_TAG']) && is_array($fields['SE_TAG']))
		{
			$templateFields['tags'] = $fields['SE_TAG'];
		}

		if (isset($fields[Entity\UF\UserField::TASK_ATTACHMENTS]))
		{
			$templateFields['fileIds'] = $fields[Entity\UF\UserField::TASK_ATTACHMENTS];
		}

		if (isset($fields['DEPENDS_ON']) && is_array($fields['DEPENDS_ON']))
		{
			$templateFields['dependsOn'] = $fields['DEPENDS_ON'];
		}

		if (isset($fields['REPLICATE_PARAMS']))
		{
			$value = null;
			if (is_array($fields['REPLICATE_PARAMS']))
			{
				$value = $fields['REPLICATE_PARAMS'];
			}
			elseif (is_string($fields['REPLICATE_PARAMS']))
			{
				$validator = new SerializedValidator();
				if ($validator->validate($fields['REPLICATE_PARAMS'])->isSuccess())
				{
					$value = unserialize($fields['REPLICATE_PARAMS'], ['allowed_classes' => false]);
				}
			}

			if ($value !== null)
			{
				$templateFields['replicateParams'] = $this->replicateParamsMapper->mapToValueObject($value);
			}
		}

		$userFields = $this->userFieldMapper->mapToCollection($fields);
		if (!$userFields->isEmpty())
		{
			$templateFields['userFields'] = $userFields;
		}

		if (isset($fields[Entity\UF\UserField::TASK_CRM]))
		{
			$templateFields['crmItemIds'] = $fields[Entity\UF\UserField::TASK_CRM];
		}

		return Entity\Template::mapFromArray($templateFields);
	}
}
