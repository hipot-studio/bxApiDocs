<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Im\Chat;

use Bitrix\Im\V2\Chat;
use Bitrix\Main\Loader;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\SystemException;

/**
 * Binds a copilot chat to the CRM entity it was opened about.
 *
 * The chat is created without a binding, so the pair is written right after the launch: it is the
 * system line the agent reads the entity from, and it survives the reuse of the chat.
 *
 * @internal
 */
class CopilotChatBinder
{
	/**
	 * @param string $entityType messenger binding literal of the entity type (MAP-01)
	 * @param string $entityId decimal identifier of the entity
	 *
	 * @throws \Bitrix\Main\LoaderException the messenger module is not available
	 * @throws ObjectNotFoundException there is no such chat
	 * @throws SystemException the binding was rejected
	 */
	public function bind(int $chatId, string $entityType, string $entityId): void
	{
		Loader::requireModule('im');

		$chat = Chat::getInstance($chatId);
		if (!$chat->isExist())
		{
			throw new ObjectNotFoundException("Chat {$chatId} does not exist");
		}

		$result = $chat
			->setEntityType($entityType)
			->setEntityId($entityId)
			->save()
		;
		if (!$result->isSuccess())
		{
			throw new SystemException(
				"Chat {$chatId} was not bound to {$entityType} {$entityId}: "
				. implode('; ', $result->getErrorMessages())
			);
		}
	}
}
