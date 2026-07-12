<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Chat\Message\Block;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\BlocksBuilder\BuilderError;
use Bitrix\Im\V2\Message\Update\UpdateService;


class Element extends BaseController
{
	/**
	 * @restMethod im.v2.Chat.Message.Block.Element.append
	 */
	public function appendAction(Message $message, array $element): ?array
	{
		if (!Features::isMessageBuilderAvailable())
		{
			$this->addError(new BuilderError(BuilderError::BLOCK_NOT_AVAILABLE));

			return null;
		}

		if (!(new UpdateService($message))->canUpdate())
		{
			$this->addError(new Message\MessageError(Message\MessageError::ACCESS_DENIED));

			return null;
		}

		$result = (new Message\BlocksBuilder\BuilderUpdater())->appendBlock($message, $element);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['result' => true];
	}

	/**
	 * @restMethod im.v2.Chat.Message.Block.Element.delete
	 */
	public function deleteAction(Message $message, string $elementId): ?array
	{
		if (!Features::isMessageBuilderAvailable())
		{
			$this->addError(new BuilderError(BuilderError::BLOCK_NOT_AVAILABLE));

			return null;
		}

		if (!(new UpdateService($message))->canUpdate())
		{
			$this->addError(new Message\MessageError(Message\MessageError::ACCESS_DENIED));

			return null;
		}

		$result = (new Message\BlocksBuilder\BuilderUpdater())->deleteBlock($message, $elementId);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['result' => true];
	}

	/**
	 * @restMethod im.v2.Chat.Message.Block.Element.update
	 */
	public function updateAction(Message $message, string $elementId, array $element): ?array
	{
		if (!Features::isMessageBuilderAvailable())
		{
			$this->addError(new BuilderError(BuilderError::BLOCK_NOT_AVAILABLE));

			return null;
		}

		if (!(new UpdateService($message))->canUpdate())
		{
			$this->addError(new Message\MessageError(Message\MessageError::ACCESS_DENIED));

			return null;
		}

		$result = (new Message\BlocksBuilder\BuilderUpdater())->updateBlock($message, $elementId, $element);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['result' => true];
	}
}
