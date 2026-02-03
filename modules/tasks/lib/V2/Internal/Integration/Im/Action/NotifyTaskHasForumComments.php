<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Im\Bot\Keyboard;
use Bitrix\Im\V2\Message\Color\Color;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

#[Recipients(creator: false, responsible: false, accomplices: false, auditors: false)]
class NotifyTaskHasForumComments extends AbstractNotify
{
	private readonly string $url;

	public function __construct(
		private readonly Entity\Task $task,
		MessageSenderInterface $sender,
	)
	{
		parent::__construct();
		$this->url = '/task/comments/' . $task->getId() . '/';
		$sender->sendMessage(task: $task, notification: $this);
	}

	public function getMessageCode(): string
	{
		return 'TASKS_IM_TASK_HAS_FORUM_COMMENTS';
	}

	public function getKeyboard(): ?Keyboard
	{
		$keyboard = new Keyboard();
		$keyboard->addButton([
			'TEXT' => Loc::getMessage('TASKS_IM_TASK_HAS_FORUM_COMMENTS_BUTTON_TEXT'),
			'LINK' => $this->url,
			'BG_COLOR_TOKEN' => Color::PRIMARY->value,
			'TEXT_COLOR' => Loc::getMessage('TASKS_IM_TASK_HAS_FORUM_COMMENTS_BUTTON_TEXT_COLOR'),
		]);

		return $keyboard;
	}

	public function getDisableNotify(): bool
	{
		return true;
	}

	public function shouldDisableAddRecent(): bool
	{
		return true;
	}
}
