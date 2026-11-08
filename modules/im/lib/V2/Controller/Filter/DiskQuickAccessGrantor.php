<?php

namespace Bitrix\Im\V2\Controller\Filter;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\ChatHolder;
use Bitrix\Im\V2\Entity\File\FileItem;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;

class DiskQuickAccessGrantor extends Base
{
	public function onBeforeAction(Event $event)
	{
		if (!Loader::includeModule('disk'))
		{
			return null;
		}

		if (!ServiceLocator::getInstance()->has('disk.scopeTokenService'))
		{
			return null;
		}

		$chat = $this->extractChat($this->getAction()->getArguments());
		if ($chat === null || !$chat->getId())
		{
			return null;
		}

		// The scope is the only access check of the quick delivery path, so access is checked here
		// instead of being left to CheckEntityAccess: prefilters are handlers of one event and do not
		// stop each other, its denial blocks the body of the action but not this filter.
		if (!$chat->checkAccess()->isSuccess())
		{
			return null;
		}

		$scopeTokenService = ServiceLocator::getInstance()->get('disk.scopeTokenService');
		$scopeTokenService->grantAccessToScope(FileItem::getQuickAccessScope($chat->getId()));
	}

	/**
	 * Takes the argument named `chat` when there is one; otherwise looks through the arguments for
	 * the first one matching by type: the chat itself or a holder carrying it.
	 */
	private function extractChat(array $arguments): ?Chat
	{
		$chat = $arguments['chat'] ?? null;
		if ($chat instanceof Chat)
		{
			return $chat;
		}

		foreach ($arguments as $argument)
		{
			if ($argument instanceof Chat)
			{
				return $argument;
			}

			if ($argument instanceof ChatHolder)
			{
				return $argument->getChat();
			}
		}

		return null;
	}
}
