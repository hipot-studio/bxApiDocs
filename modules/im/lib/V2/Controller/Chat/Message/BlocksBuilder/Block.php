<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Chat\Message\BlocksBuilder;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\BlocksBuilder\BuilderError;
use Bitrix\Im\V2\Message\Update\UpdateService;


class Block extends BaseController
{
	/**
	 * @restMethod im.v2.Chat.Message.BlocksBuilder.Block.append
	 */
	public function appendAction(Message $message, array $block): ?array
	{
		if (!Features::isMessageBuilderAvailable())
		{
			$this->addError(new BuilderError(BuilderError::BUILDER_NOT_AVAILABLE));

			return null;
		}

		if (!(new UpdateService($message))->canUpdate())
		{
			$this->addError(new Message\MessageError(Message\MessageError::ACCESS_DENIED));

			return null;
		}

		$result = (new Message\BlocksBuilder\BuilderUpdater())->appendBlock($message, $block);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return [
			'messageId' => $message->getId(),
			'chatId' => $message->getChatId(),
			'text' => $message->getParsedMessage(),
			'block' => $result->getResult(),
		];
	}

	/**
	 * @restMethod im.v2.Chat.Message.BlocksBuilder.Block.delete
	 */
	public function deleteAction(Message $message, string $blockId): ?array
	{
		if (!Features::isMessageBuilderAvailable())
		{
			$this->addError(new BuilderError(BuilderError::BUILDER_NOT_AVAILABLE));

			return null;
		}

		if (!(new UpdateService($message))->canUpdate())
		{
			$this->addError(new Message\MessageError(Message\MessageError::ACCESS_DENIED));

			return null;
		}

		$result = (new Message\BlocksBuilder\BuilderUpdater())->deleteBlock($message, $blockId);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return [
			'messageId' => $message->getId(),
			'chatId' => $message->getChatId(),
			'text' => $message->getParsedMessage(),
			'blockId' => $blockId,
		];
	}

	/**
	 * @restMethod im.v2.Chat.Message.BlocksBuilder.Block.update
	 */
	public function updateAction(Message $message, string $blockId, array $block): ?array
	{
		if (!Features::isMessageBuilderAvailable())
		{
			$this->addError(new BuilderError(BuilderError::BUILDER_NOT_AVAILABLE));

			return null;
		}

		if (!(new UpdateService($message))->canUpdate())
		{
			$this->addError(new Message\MessageError(Message\MessageError::ACCESS_DENIED));

			return null;
		}

		$result = (new Message\BlocksBuilder\BuilderUpdater())->updateBlock($message, $blockId, $block);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return [
			'messageId' => $message->getId(),
			'chatId' => $message->getChatId(),
			'text' => $message->getParsedMessage(),
			'blockId' => $blockId,
			'block' => $result->getResult(),
		];
	}
}
