<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Item;
use Bitrix\Crm\Result;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response\Field;
use Bitrix\Main\Type\DateTime;

final readonly class OpenLineService
{
	public function __construct(
		private TimelineService $timelineService,
		private ItemService $itemService,
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
		if (!$this->hasAccess($userId, $entityTypeId, $entityId))
		{
			return Result::failAccessDenied();
		}

		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		$item = $factory->getItem($entityId, [
			Item::FIELD_NAME_ID,
			Item::FIELD_NAME_ASSIGNED,
		]);

		if ($item === null)
		{
			return Result::fail("Item with ID {$entityId} was not found for entityTypeId {$entityTypeId}.");
		}

		return $this->timelineService->createComment(
			entityTypeId: $entityTypeId,
			entityId: $entityId,
			comment: $comment,
			userId: $item->getAssignedById(),
			checkPermissions: false,
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
		if (!$this->hasAccess($userId, $entityTypeId, $entityId))
		{
			return Result::failAccessDenied();
		}

		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		$item = $factory->getItem($entityId, [
			Item::FIELD_NAME_ID,
			Item::FIELD_NAME_ASSIGNED,
		]);

		if ($item === null)
		{
			return Result::fail("Item with ID {$entityId} was not found for entityTypeId {$entityTypeId}.");
		}

		return $this->timelineService->createToDo(
			entityTypeId: $entityTypeId,
			entityId: $entityId,
			title: $title,
			description: $description,
			deadline: $deadline,
			userId: $item->getAssignedById(),
			checkPermissions: false,
		);
	}

	public function appendUserFieldValue(
		int $userId,
		int $entityTypeId,
		int $entityId,
		string $fieldId,
		string $fieldValue,
	): Result
	{
		if (!$this->hasAccess($userId, $entityTypeId, $entityId))
		{
			return Result::failAccessDenied();
		}

		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		$commentField = $factory->getFieldsCollection()->getField(Item::FIELD_NAME_COMMENTS);
		if ($commentField?->getName() === $fieldId)
		{
			$item = $factory->getItem($entityId, [
				Item::FIELD_NAME_ID,
				Item::FIELD_NAME_ASSIGNED,
				Item::FIELD_NAME_COMMENTS,
			]);

			if ($item === null)
			{
				return Result::fail("Item with ID {$entityId} was not found for entityTypeId {$entityTypeId}.");
			}

			$this->appendValueToComments($item, $fieldValue);

			$result = $this->itemService->updateItem($item->getAssignedById(), $item);
			if (!$result->isSuccess())
			{
				return Result::fail($result->getErrorCollection());
			}

			return Result::success();
		}

		$item = $factory->getItem($entityId, [
			Item::FIELD_NAME_ID,
			Item::FIELD_NAME_ASSIGNED,
		]);

		if ($item === null)
		{
			return Result::fail("Item with ID {$entityId} was not found for entityTypeId {$entityTypeId}.");
		}

		return $this->itemService->appendUserFieldValue(
			userId: $item->getAssignedById(),
			entityTypeId: $entityTypeId,
			itemId: $item->getId(),
			fieldId: $fieldId,
			fieldValue: $fieldValue,
			checkPermissions: false,
		);
	}

	public function getEditableUserFieldList(
		int $userId,
		int $entityTypeId,
		int $entityId,
	): Result
	{
		if (!$this->hasAccess($userId, $entityTypeId, $entityId))
		{
			return Result::failAccessDenied();
		}

		$result = $this->itemService->listUserFields($userId, $entityTypeId, checkPermissions: false);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$fields = $result->get('fields');
		$fields = $this->appendCommentField($entityTypeId, $fields);

		return Result::success(fields: $fields);
	}

	private function appendCommentField(
		int $entityTypeId,
		array $fields,
	): array
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return $fields;
		}

		$commentField = $factory->getFieldsCollection()->getField(Item::FIELD_NAME_COMMENTS);
		if ($commentField !== null)
		{
			$fields[] = Field::fromField($commentField);
		}

		return $fields;
	}

	// todo: refactor duplicate code \Bitrix\Crm\Integration\AI\Operation\FillItemFieldsFromCallTranscription::appendComment
	private function appendValueToComments(Item $item, string $fieldValue): void
	{
		$localization = Container::getInstance()->getLocalization();
		$localization->loadMessages();

		$copilotSuffix = '[p]' . AIManager::getCopilotName() . PHP_EOL . $fieldValue . '[/p]';

		$oldComment = $item->getComments();
		if (empty($oldComment))
		{
			$item->setComments($copilotSuffix);

			return;
		}

		if (
			str_ends_with($oldComment, "\n\n")
			|| str_ends_with($oldComment, "\r\n\r\n")
			|| str_ends_with($oldComment, "\r\n\n")
			|| str_ends_with($oldComment, "\n\r\n")
		)
		{
			$numberOfLineBreaksToAdd = 0;
		}
		elseif (str_ends_with($oldComment, "\n") || str_ends_with($oldComment, "\r\n"))
		{
			$numberOfLineBreaksToAdd = 1;
		}
		else
		{
			$numberOfLineBreaksToAdd = 2;
		}

		while ($numberOfLineBreaksToAdd > 0)
		{
			$oldComment .= PHP_EOL;
			$numberOfLineBreaksToAdd--;
		}

		$item->setComments($oldComment . $copilotSuffix);
	}

	private function hasAccess(int $userId, int $entityTypeId, int $entityId): bool
	{
		$permissions = Container::getInstance()
			->getUserPermissions($userId)
			->itemFromOpenLine()
		;

		return $permissions->hasAccessFromMcpTool($entityTypeId, $entityId);
	}
}
