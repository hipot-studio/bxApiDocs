<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Internals\Task\TemplateTable;
use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;
use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Repository\CrmItemRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\CheckListRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\GroupRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\TemplateMapper;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;

class TemplateReadRepository implements TemplateReadRepositoryInterface
{
	public function __construct(
		private readonly TemplateTagRepositoryInterface $templateTagRepository,
		private readonly ParentTemplateRepositoryInterface $parentTemplateRepository,
		private readonly GroupRepositoryInterface $groupRepository,
		private readonly UserRepositoryInterface $userRepository,
		private readonly CheckListRepositoryInterface $checkListRepository,
		private readonly CrmItemRepositoryInterface $crmItemRepository,
		private readonly RelatedTaskTemplateRepositoryInterface $templateRelatedTaskRepository,
		private readonly TemplatePermissionRepository $templatePermissionRepository,
		private readonly SubTemplateRepositoryInterface $subTemplateRepository,
		private readonly TemplateMapper $mapper,
	)
	{
	}

	public function getById(int $id, ?Select $select = null): ?Template
	{
		$selectFields = [
			'*',
		];

		$select ??= new Select();

		if ($select->members)
		{
			$selectFields[] = 'MEMBERS';
		}

		if ($select->userFields)
		{
			$selectFields[] = 'UF_*';
		}

		$template =
			TemplateTable::query()
				->setSelect($selectFields)
				->where('ID', $id)
				->fetchObject()
		;

		if ($template === null)
		{
			return null;
		}

		$tags = null;
		if ($select->tags)
		{
			$tags = $this->templateTagRepository->getById($id);
		}

		$group = null;
		if ($select->group && $template->getGroupId() > 0)
		{
			$group = $this->groupRepository->getById($template->getGroupId());
		}

		$members = null;
		if ($select->members)
		{
			$memberIds = array_merge(
				$template->getMembers()->getUserIdList(),
				[$template->getCreatedBy(), $template->getResponsibleId()]
			);

			Collection::normalizeArrayValuesByInt($memberIds, false);

			$members = $this->userRepository->getByIds($memberIds);
		}

		$checkListIds = null;
		if ($select->checkLists)
		{
			$checkListIds = $this->checkListRepository->getIdsByEntity($id, Type::Template);
		}

		$crmItemIds = null;
		if ($select->crm)
		{
			$crmItemIds = $this->crmItemRepository->getIdsByTemplateId($id);
		}

		$permissions = null;
		if ($select->permissions)
		{
			$permissions = $this->templatePermissionRepository->getPermissions($id);
		}

		$containsRelatedTasks = false;
		if ($select->relatedTasks)
		{
			$containsRelatedTasks = $this->templateRelatedTaskRepository->containsRelatedTasks($id);
		}

		$containsSubTemplates = false;
		if ($select->subTemplates)
		{
			$containsSubTemplates = $this->subTemplateRepository->containsSubTemplates($id);
		}

		$parent = null;
		if ($select->parent)
		{
			$parent = $this->parentTemplateRepository->getParent($id);
		}

		$aggregates = [
			'containsCheckList' => !empty($checkListIds),
			'containsRelatedTasks' => $containsRelatedTasks,
			'containsSubTemplates' => $containsSubTemplates,
		];

		$fileIds = $template->get(UserField::TASK_ATTACHMENTS);
		if (empty($fileIds))
		{
			$fileIds = null;
		}

		return $this->mapper->mapFromTemplateObject(
			templateObject: $template,
			tags: $tags,
			group: $group,
			members: $members,
			crmItemIds: $crmItemIds,
			checkListIds: $checkListIds,
			aggregates: $aggregates,
			permissions: $permissions,
			fileIds: $fileIds,
			parent: $parent,
		);
	}

	public function getAttachmentIds(int $templateId): array
	{
		$row =
			TemplateTable::query()
				->setSelect(['ID', UserField::TASK_ATTACHMENTS])
				->where('ID', $templateId)
				->fetch()
		;

		if (!is_array($row))
		{
			return [];
		}

		$value = $row[UserField::TASK_ATTACHMENTS] ?? null;

		if (!is_array($value))
		{
			return [];
		}

		return $value;
	}
}
