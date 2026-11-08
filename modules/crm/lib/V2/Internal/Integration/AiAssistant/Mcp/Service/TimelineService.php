<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service;

use Bitrix\Crm\Activity\Entity\ToDo;
use Bitrix\Crm\Activity\Provider\ToDo\ToDo as ToDoProvider;
use Bitrix\Crm\Activity\ToDo\ColorSettings\ColorSettingsProvider;
use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Result;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Timeline\CommentController;
use Bitrix\Crm\Timeline\CommentEntry;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response;
use Bitrix\Main\Type\DateTime;

final class TimelineService
{
	public function createComment(
		int $entityTypeId,
		int $entityId,
		string $comment,
		int $userId,
		bool $checkPermissions = true,
	): Result
	{
		$item = Container::getInstance()
			->getFactory($entityTypeId)
			?->getItem(
				id: $entityId,
				fieldsToSelect: [
					Item::FIELD_NAME_ID,
				],
			)
		;

		if ($item === null)
		{
			return Result::fail("Item with ID {$entityId} was not found for entityTypeId {$entityTypeId}. Use list_items to find item IDs.");
		}

		if ($checkPermissions)
		{
			$permissions = Container::getInstance()->getUserPermissions($userId)->item();
			if (!$permissions->canUpdateItem($item))
			{
				return Result::failAccessDenied();
			}
		}

		$commentId = CommentEntry::create([
			'TEXT' => $comment,
			'SETTINGS' => [
				'HAS_FILES' => 'N',
				'IS_AI_CREATED' => 'Y',
			],
			'BINDINGS' => [
				[
					'ENTITY_TYPE_ID' => $item->getEntityTypeId(),
					'ENTITY_ID' => $item->getId(),
				],
			],
			'AUTHOR_ID' => $userId,
		]);

		if ($commentId <= 0)
		{
			return Result::fail("Failed to create comment for item {$entityId} of entityTypeId {$entityTypeId}.");
		}

		CommentController::getInstance()->onCreate(
			$commentId,
			[
				'COMMENT' => $comment,
				'ENTITY_TYPE_ID' => $entityTypeId,
				'ENTITY_ID' => $entityId,
			]
		);

		$responseComment = new Response\Timeline\Comment(
			$entityTypeId,
			$entityId,
			$comment,
			$userId,
		);

		return Result::success(comment: $responseComment);
	}

	public function createToDo(
		int $entityTypeId,
		int $entityId,
		string $title,
		string $description,
		DateTime $deadline,
		int $userId,
		bool $checkPermissions = true,
	): Result
	{
		$item = Container::getInstance()
			->getFactory($entityTypeId)
			?->getItem(
				id: $entityId,
				fieldsToSelect: [
					Item::FIELD_NAME_ID,
				],
			);

		if ($item === null)
		{
			return Result::fail("Item with ID {$entityId} was not found for entityTypeId {$entityTypeId}. Use list_items to find item IDs.");
		}

		if ($checkPermissions)
		{
			$permissions = Container::getInstance()->getUserPermissions($userId)->item();
			if (!$permissions->canUpdateItem($item))
			{
				return Result::failAccessDenied();
			}
		}

		$owner = new ItemIdentifier($entityTypeId, $entityId);
		$todo = (new ToDo($owner, new ToDoProvider()))
			->setSubject($title)
			->setDescription($description)
			->setDeadline($deadline)
			->setResponsibleId($userId)
			->setColorId(ColorSettingsProvider::COPILOT_COLOR_ID)
			->setSettings([
				'IS_AI_CREATED' => true,
			])
			->setCheckPermissions(false)
		;

		$saveResult = $todo->save(useCurrentSettings: true);
		if (!$saveResult->isSuccess())
		{
			return Result::fail($saveResult->getErrorCollection());
		}

		$responseToDo = Response\Timeline\ToDo::fromObject($entityTypeId, $entityId, $todo);

		return Result::success(todo: $responseToDo);
	}
}
