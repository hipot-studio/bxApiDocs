<?php

namespace Bitrix\Crm\Integration\AiAssistant\Tools\Timeline\Comment;

use Bitrix\Crm\Integration\AiAssistant\Tools\BaseCrmTool;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Timeline\CommentController;
use Bitrix\Crm\Timeline\CommentEntry;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;

abstract class BaseCommentTool extends BaseCrmTool
{
	#[PositiveNumber(errorMessage: 'User ID must be a positive integer')]
	protected int $userId;

	#[NotEmpty(errorMessage: 'Comment text must not be empty')]
	protected string $comment;

	protected function executeTool(int $userId, ...$args): string
	{
		$this->parseInput([
			'userId' => $userId,
			...$args,
		]);

		$validator = ServiceLocator::getInstance()->get('main.validation.service');
		$validationResult = $validator->validate($this);
		if (!$validationResult->isSuccess())
		{
			return 'Validation errors: ' . implode(', ', $validationResult->getErrorMessages());
		}

		return $this->innerExecute();
	}

	protected function parseInput(array $args): void
	{
		$this->userId = (int)($args['userId'] ?? 0);
		$this->comment = mb_trim((string)($args['comment'] ?? ''));
	}

	protected function createComment(int $entityTypeId, int $entityId): string
	{
		$canEdit = Container::getInstance()
			->getUserPermissions($this->userId)
			->item()
			->canUpdate($entityTypeId, $entityId)
		;

		if (!$canEdit)
		{
			return 'User doesn\'t have permissions to edit this entity';
		}

		$factory = Container::getInstance()->getFactory($entityTypeId);
		if (!$factory)
		{
			return 'Entity type not supported';
		}

		$item = $factory->getItem($entityId, ['ID']);
		if (!$item)
		{
			return 'Entity not found';
		}

		try
		{
			$commentId = CommentEntry::create([
				'TEXT' => $this->comment,
				'SETTINGS' => [
					'HAS_FILES' => 'N',
					'IS_AI_CREATED' => 'Y',
				],
				'BINDINGS' => [['ENTITY_TYPE_ID' => $entityTypeId, 'ENTITY_ID' => $entityId]],
				'AUTHOR_ID' => $this->userId,
			]);
		}
		catch (\Throwable $exception)
		{
			return 'Error creating comment: ' . $exception->getMessage();
		}

		if ($commentId <= 0)
		{
			return 'Error creating comment';
		}

		CommentController::getInstance()->onCreate(
			$commentId,
			[
				'COMMENT' => $this->comment,
				'ENTITY_TYPE_ID' => $entityTypeId,
				'ENTITY_ID' => $entityId,
			],
		);

		return 'Comment created successfully';
	}
}
