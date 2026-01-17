<?php

declare(strict_types=1);


namespace Bitrix\Tasks\V2\Internal\Service\Comment;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Comments\Internals\Comment;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Comment\Trait\LoadMessagesTrait;
use Bitrix\Tasks\V2\Internal\Service\User\Trait\FormatUserTrait;

class AiFailedToResolveResponsibleComment extends Comment
{
	use FormatUserTrait;
	use LoadMessagesTrait;

	private const MESSAGE_KEY = 'COMMENT_POSTER_COMMENT_TASK_ADD_AI_FAILED_TO_RESOLVE_RESPONSIBLE';

	public function __construct(Entity\Task $task)
	{
		$replace = [
			'#RESPONSIBLE#' => $this->formatUser($task->responsible),
		];

		$this->loadPosterMessages();

		parent::__construct(
			Loc::getMessage(self::MESSAGE_KEY, $replace),
			$task->responsible?->id,
			Comment::TYPE_ADD,
			[[self::MESSAGE_KEY, array_merge($replace, [])]],
		);
	}
}
