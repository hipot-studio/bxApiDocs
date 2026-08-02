<?
/**
 * This class is for internal use only, not a part of public API.
 * It can be changed at any time without notification.
 *
 * @access private
 */

namespace Bitrix\Tasks\Integration;

use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Tasks\Integration\IM\ChatEntity;

abstract class IM extends \Bitrix\Tasks\Integration\Integration
{
	const MODULE_NAME = 'im';

	public static function notifyAdd($message)
	{
		if(!static::includeModule())
		{
			return false;
		}

		return \CIMNotify::Add($message);
	}

	/**
	 * Returns the entity (type and id) the chat is bound to, or null when there is none.
	 */
	public static function getChatEntity(int $chatId): ?ChatEntity
	{
		if ($chatId <= 0 || !static::includeModule())
		{
			return null;
		}

		$chat = ChatFactory::getInstance()->getChatById($chatId);

		$entityType = $chat->getEntityType();
		$entityId = $chat->getEntityId();

		if ($entityType === null || $entityId === null)
		{
			return null;
		}

		return new ChatEntity($entityType, $entityId);
	}

	/**
	 * Adds the user to the chat unless he is already a member of it.
	 */
	public static function joinUserToChat(int $chatId, int $userId): void
	{
		if ($chatId <= 0 || $userId <= 0 || !static::includeModule())
		{
			return;
		}

		$chatData = \CIMChat::getChatData(['ID' => $chatId]);
		if (!$chatData)
		{
			return;
		}

		$userIds = $chatData['userInChat'][$chatId] ?? [];
		if (in_array($userId, $userIds))
		{
			return;
		}

		(new \CIMChat(0))->addUser($chatId, $userId);
	}
}
