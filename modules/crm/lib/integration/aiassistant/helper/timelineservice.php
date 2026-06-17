<?php

namespace Bitrix\Crm\Integration\AiAssistant\Helper;

use Bitrix\Crm\Activity\Entity\ToDo;
use Bitrix\Crm\Activity\Provider\ToDo\ToDo as ToDoProvider;
use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Result;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Timeline\CommentController;
use Bitrix\Crm\Timeline\CommentEntry;
use Bitrix\Main\Type\DateTime;

final class TimelineService
{
	public function createComment(
		int $entityTypeId,
		int $entityId,
		string $comment,
		int $authorId,
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
			return Result::failNotFound();
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
			'AUTHOR_ID' => $authorId,
		]);

		if ($commentId <= 0)
		{
			return Result::fail('Failed to create comment');
		}

		CommentController::getInstance()->onCreate(
			$commentId,
			[
				'COMMENT' => $comment,
				'ENTITY_TYPE_ID' => $entityTypeId,
				'ENTITY_ID' => $entityId,
			]
		);

		return Result::success(commentId: $commentId);
	}

	public function createToDo(
		int $entityTypeId,
		int $entityId,
		string $title,
		string $description,
		DateTime $deadline,
		int $responsibleId,
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
			return Result::failNotFound();
		}

		$owner = new ItemIdentifier($entityTypeId, $entityId);
		$todo = (new ToDo($owner, new ToDoProvider()))
			->setSubject($title)
			->setDescription($description)
			->setDeadline($deadline)
			->setResponsibleId($responsibleId)
			->setColorId('5')
			->setSettings(['IS_AI_CREATED' => true])
			->setCheckPermissions(false)
		;

		$saveResult = $todo->save(useCurrentSettings: true);
		if (!$saveResult->isSuccess())
		{
			return Result::fail($saveResult->getErrorCollection());
		}

		return Result::success(todo: $todo);
	}
}
