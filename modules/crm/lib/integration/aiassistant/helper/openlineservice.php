<?php

namespace Bitrix\Crm\Integration\AiAssistant\Helper;

use Bitrix\Crm\Item;
use Bitrix\Crm\Result;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Type\DateTime;

final readonly class OpenLineService
{
	public function __construct(
		private TimelineService $timelineService,
		private UserFieldHelper $userFieldHelper,
	)
	{
	}

	public function createComment(
		int $userId,
		int $entityTypeId,
		int $entityId,
		string $comment,
	): Result
	{
		$item = $this->getItem($entityTypeId, $entityId);
		if ($item === null)
		{
			return Result::failNotFound();
		}

		if (!$this->hasAccess($userId, $item))
		{
			return Result::failAccessDenied();
		}

		return $this->timelineService->createComment(
			entityTypeId: $entityTypeId,
			entityId: $entityId,
			comment: $comment,
			authorId: $item->getAssignedById(),
		);
	}

	public function createToDo(
		int $userId,
		int $entityTypeId,
		int $entityId,
		string $title,
		string $description,
		DateTime $deadline,
	): Result
	{
		$item = $this->getItem($entityTypeId, $entityId);
		if ($item === null)
		{
			return Result::failNotFound();
		}

		if (!$this->hasAccess($userId, $item))
		{
			return Result::failAccessDenied();
		}

		return $this->timelineService->createToDo(
			entityTypeId: $entityTypeId,
			entityId: $entityId,
			title: $title,
			description: $description,
			deadline: $deadline,
			responsibleId: $item->getAssignedById(),
		);
	}

	public function addUserFieldValue(
		int $userId,
		int $entityTypeId,
		int $entityId,
		string $fieldId,
		string $fieldValue,
	): Result
	{
		$item = $this->getItem($entityTypeId, $entityId);
		if ($item === null)
		{
			return Result::failNotFound();
		}

		if (!$this->hasAccess($userId, $item))
		{
			return Result::failAccessDenied();
		}

		return $this->userFieldHelper->addUserFieldValue(
			entityTypeId: $entityTypeId,
			entityId: $entityId,
			fieldId: $fieldId,
			fieldValue: $fieldValue,
			userId: $userId,
		);
	}

	public function getEditableUserFieldList(
		int $userId,
		int $entityTypeId,
		int $entityId,
	): Result
	{
		$item = $this->getItem($entityTypeId, $entityId);
		if ($item === null)
		{
			return Result::failNotFound();
		}

		if (!$this->hasAccess($userId, $item))
		{
			return Result::failAccessDenied();
		}

		return $this->userFieldHelper->getEditableUserFieldList(
			entityTypeId: $entityTypeId,
			entityId: $entityId,
		);
	}

	private function getItem(int $entityTypeId, int $entityId): ?Item
	{
		return Container::getInstance()
			->getFactory($entityTypeId)
			?->getItem(
				id: $entityId,
				fieldsToSelect: [
					Item::FIELD_NAME_ID,
					Item::FIELD_NAME_ASSIGNED,
				],
			)
		;
	}

	private function hasAccess(int $userId, Item $item): bool
	{
		$permissions = Container::getInstance()
			->getUserPermissions($userId)
			->itemFromOpenLine()
		;

		return $permissions->hasAccessFromMcpTool($item->getEntityTypeId(), $item->getId());
	}
}
