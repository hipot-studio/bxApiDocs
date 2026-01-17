<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Im\Bot\Keyboard;
use Bitrix\Im\V2\Message\Color\Color;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

#[Recipients(creator: false, responsible: false, accomplices: false, auditors: false)]
class NotifyTaskHasLegacyChat extends AbstractNotify
{
	private readonly string $url;

	public function __construct(
		private readonly Entity\Task $task,
		MessageSenderInterface $sender,
		array $args = [],
	)
	{
		parent::__construct();
		if (!array_key_exists('chatId', $args))
		{
			return;
		}

		$this->url = '/online/?IM_DIALOG=chat' . $args['chatId'];
		$sender->sendMessage(task: $task, notification: $this);
	}

	public function getMessageCode(): string
	{
		return 'TASKS_IM_TASK_HAS_LEGACY_CHAT';
	}

	public function getKeyboard(): ?Keyboard
	{
		$keyboard = new Keyboard();
		$keyboard->addButton([
			'TEXT' => Loc::getMessage('TASKS_IM_TASK_HAS_LEGACY_CHAT_BUTTON_TEXT'),
			'LINK' => $this->url,
			'BG_COLOR_TOKEN' => Color::PRIMARY->value,
			'TEXT_COLOR' => Loc::getMessage('TASKS_IM_TASK_HAS_LEGACY_CHAT_BUTTON_TEXT_COLOR'),
		]);

		return $keyboard;
	}
}
