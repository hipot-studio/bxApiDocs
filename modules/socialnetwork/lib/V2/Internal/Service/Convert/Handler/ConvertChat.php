<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler;

use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Exceptions\ProjectConvertChatException;

class ConvertChat implements HandlerInterface
{
	/**
	 * @throws ProjectConvertChatException
	 */
	public function __invoke(Workgroup $groupBefore, int $userId, Workgroup $groupAfter): void
	{
		$chatId = $groupAfter->getChatId();

		if ($chatId <= 0)
		{
			throw new ProjectConvertChatException('Chat was not created for the group');
		}

		$chatService = Container::getInstance()->getConvertChatService();

		$result = $chatService->convertChat(
			$chatId,
			Type::Collab,
			$groupAfter->getName(),
			$groupAfter->isOpened(),
		);

		if (!$result->isSuccess())
		{
			throw new ProjectConvertChatException(
				implode(', ', $result->getErrorMessages())
			);
		}

		$chatService->sendConvertMessageRich($groupAfter->getId(), $userId);
	}
}
