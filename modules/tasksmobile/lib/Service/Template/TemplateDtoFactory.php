<?php

namespace Bitrix\TasksMobile\Service\Template;

use Bitrix\Tasks\V2\Internal\Entity\Priority as TemplatePriority;
use Bitrix\TasksMobile\Dto\TaskTemplateDto;
use Bitrix\TasksMobile\Dto\TaskTemplateTagDto;
use Bitrix\TasksMobile\Enum\TaskPriority;

final class TemplateDtoFactory
{
	/**
	 * @param \Bitrix\Tasks\V2\Internal\Entity\Template $templateEntity
	 * @param callable|null $checklistConverter fn(?array $checklist): ?array
	 * @return array{template: TaskTemplateDto, userIds: int[], groupId: ?int}
	 */
	public function make(
		\Bitrix\Tasks\V2\Internal\Entity\Template $templateEntity,
		TaskTemplateDataBuilder $dataBuilder,
		?callable $checklistConverter = null,
	): array
	{
		$userFieldData = $this->extractUserFieldData($templateEntity);

		$creatorId = $templateEntity->creator?->id;
		$responsibleId = $this->getFirstUserId($templateEntity->responsibleCollection);
		$accompliceIds = $this->getUserIdsFromCollection($templateEntity->accomplices);
		$auditorIds = $this->getUserIdsFromCollection($templateEntity->auditors);

		$tagNames = $this->extractTagNames($templateEntity);

		$groupId = $templateEntity->groupId;
		if ($groupId !== null && $groupId <= 0)
		{
			$groupId = null;
		}

		$priority = $templateEntity->priority;
		$priorityEnum = ($priority === TemplatePriority::High) ? TaskPriority::High : TaskPriority::Normal;

		$checklist = $dataBuilder->prepareChecklist();
		$checklist = $checklistConverter ? $checklistConverter($checklist) : $checklist;

		$template = new TaskTemplateDto(
			id: $templateEntity->id ?? 0,
			name: $templateEntity->title ?? '',
			description: $templateEntity->description ?? '',
			priority: $priorityEnum,
			creatorId: $creatorId,
			accomplices: $accompliceIds,
			auditors: $auditorIds,
			files: $dataBuilder->prepareDiskFiles(),
			checklist: $checklist,
			tags: array_map(
				static fn(string $tag) => new TaskTemplateTagDto(id: $tag, name: $tag),
				$tagNames,
			),
			crm: $dataBuilder->prepareCrmElementsByIds($templateEntity->crmItemIds),
			groupId: $groupId,
			responsibleId: $responsibleId,
			isRepeatable: (bool)$templateEntity->replicate,
			replicateParams: $templateEntity->replicateParams?->toArray(),
			deadlineAfter: $templateEntity->deadlineAfter ?? 0,
			allowChangeDeadline: (bool)$templateEntity->allowsChangeDeadline,
			allowTimeTracking: (bool)$templateEntity->allowsTimeTracking,
			allowTaskControl: (bool)$templateEntity->needsControl,
			isMatchWorkTime: (bool)$templateEntity->matchesWorkTime,
			isResultRequired: (bool)$templateEntity->requireResult,
			timeEstimate: $templateEntity->estimatedTime ?? 0,
			startDatePlanAfter: $templateEntity->startDatePlanAfter ?? 0,
			endDatePlanAfter: $templateEntity->endDatePlanAfter ?? 0,
			addInReport: (bool)$templateEntity->addInReport,
			descriptionInBbcode: $templateEntity->descriptionInBbcode ?? true,
			userFields: $dataBuilder->prepareUserFields($userFieldData),
		);

		$userIds = array_unique(
			array_filter(array_merge(
				[$template->creatorId],
				$template->accomplices,
				$template->auditors,
				[$template->responsibleId]
			))
		);

		return [
			'template' => $template,
			'userIds' => $userIds,
			'groupId' => $groupId,
		];
	}

	/**
	 * @param \Bitrix\Tasks\V2\Internal\Entity\Template $templateEntity
	 */
	private function extractUserFieldData(\Bitrix\Tasks\V2\Internal\Entity\Template $templateEntity): array
	{
		$data = [];
		if (!$templateEntity->userFields)
		{
			return $data;
		}

		foreach ($templateEntity->userFields as $userField)
		{
			$key = $userField?->key;
			if (!$key)
			{
				continue;
			}

			$data[$key] = $userField->value;
		}

		return $data;
	}

	/**
	 * @param \Bitrix\Tasks\V2\Internal\Entity\Template $templateEntity
	 * @return string[]
	 */
	private function extractTagNames(\Bitrix\Tasks\V2\Internal\Entity\Template $templateEntity): array
	{
		$tagNames = [];
		if (!$templateEntity->tags)
		{
			return $tagNames;
		}

		foreach ($templateEntity->tags as $tag)
		{
			$name = $tag->name ?? '';
			if ($name !== '')
			{
				$tagNames[] = $name;
			}
		}

		return $tagNames;
	}

	private function getFirstUserId(?\Bitrix\Tasks\V2\Internal\Entity\UserCollection $collection): int
	{
		if ($collection)
		{
			foreach ($collection as $user)
			{
				$id = $user->id ?? 0;

				return $id > 0 ? $id : 0;
			}
		}

		return 0;
	}

	/**
	 * @return int[]
	 */
	private function getUserIdsFromCollection(?\Bitrix\Tasks\V2\Internal\Entity\UserCollection $collection): array
	{
		if (!$collection)
		{
			return [];
		}

		$result = [];
		foreach ($collection as $user)
		{
			$id = $user->id ?? 0;
			if ($id > 0)
			{
				$result[] = $id;
			}
		}

		return array_values(array_unique($result));
	}
}
