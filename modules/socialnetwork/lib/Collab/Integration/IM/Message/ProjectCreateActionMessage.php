<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\IM\Message;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Socialnetwork\Collab\Integration\IM\Message\ProjectCreate\ProjectAttachBuilder;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;

class ProjectCreateActionMessage implements ActionMessageInterface
{
	use MessageTrait;

	public function __construct(
		protected int $collabId,
		protected int $senderId,
	)
	{
	}

	/**
	 * @throws LoaderException
	 * @throws ObjectNotFoundException
	 */
	public function send(array $recipientIds = [], array $parameters = []): int
	{
		if (!Loader::includeModule('im'))
		{
			return 0;
		}

		$project = $this->getWorkgroup($this->collabId);
		$message = $this->buildMessage($project, $parameters);

		$chat = Chat::getInstance($project->getChatId());
		$result = $chat->sendMessage($message);

		return $result->getMessageId() ?? 0;
	}

	/**
	 * @throws ObjectNotFoundException
	 */
	private function buildMessage(Workgroup $project, array $parameters): Message
	{
		$message = (new Message())
			->setAuthorId($this->senderId)
			->setChatId($project->getChatId())
			->setMessage($this->buildMessageText())
			->markAsSystem(true)
		;

		$attach = $this->getAttachBuilder()->build($project, $this->senderId, $parameters);
		$message->getAttach()->addValue($attach);

		return $message;
	}

	private function getAttachBuilder(): ProjectAttachBuilder
	{
		return Container::getInstance()->getProjectAttachBuilder();
	}

	private function buildMessageText(): string
	{
		return (string)Loc::getMessage(
			'SOCIALNETWORK_CHAT_PROJECT_CREATE' . $this->getGenderSuffix($this->senderId),
			['#SENDER_NAME#' => "[USER={$this->senderId}][/USER]"],
		);
	}
}
