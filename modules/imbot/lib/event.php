<?php
namespace Bitrix\ImBot;

use Bitrix\Im\V2\Entity\User\Data\BotData;

/**
 * Bot event dispatcher.
 * @package \Bitrix\ImBot
 */
class Event
{
	/**
	 * Handler for "im:OnAfterUserRead" event.
	 * @see \CIMMessage::SetReadMessage
	 *
	 * @param array $params
	 * @return bool
	 */
	public static function onUserRead($params)
	{
		$className = BotData::getInstance((int)$params['DIALOG_ID'])->getClass();
		
		if (
			!empty($className)
			&& class_exists($className)
			&& method_exists($className, 'onUserRead')
		)
		{
			return call_user_func_array(array($className, 'onUserRead'), [$params]);
		}
		
		return true;
	}

	/**
	 * Handler for "im:OnAfterChatRead" event.
	 * @see \CIMChat::SetReadMessage
	 *
	 * @param array $params
	 * @return bool
	 */
	public static function onChatRead($params)
	{
		$botList = [];
		$relations = \CIMChat::GetRelationById($params['CHAT_ID'], false, false, false);
		foreach ($relations as $relation)
		{
			if ($relation['EXTERNAL_AUTH_ID'] === \Bitrix\Im\Bot::EXTERNAL_AUTH_ID)
			{
				$botList[(int)$relation['USER_ID']] = (int)$relation['USER_ID'];
			}
		}

		$result = true;
		foreach ($botList as $botId)
		{
			$className = BotData::getInstance($botId)->getClass();

			if (
				!empty($className)
				&& class_exists($className)
				&& method_exists($className, 'onChatRead')
			)
			{
				$result = call_user_func_array([$className, 'onChatRead'], [$params]);
			}
		}

		return $result;
	}

	/**
	 * Handler for "im:OnAfterMessagesLike" event.
	 * @see \CIMMessenger::Like
	 *
	 * @param array $params
	 * @return bool
	 */
	public static function onMessageLike($params)
	{
		if ($params['CHAT']['TYPE'] == \IM_MESSAGE_PRIVATE)
		{
			$botId = $params['DIALOG_ID'];
		}
		else
		{
			$botId = $params['MESSAGE']['AUTHOR_ID'];
		}
		
		$className = BotData::getInstance((int)$botId)->getClass();
		if (empty($className))
		{
			return true;
		}
		
		Log::write($params, 'MESSAGE LIKE');
		
		if (class_exists($className) && method_exists($className, 'onMessageLike'))
		{
			return call_user_func_array(array($className, 'onMessageLike'), [$params]);
		}
		
		return true;
	}

	/**
	 * Handler for "im:OnStartWriting" event.
	 * @see \CIMMessenger::StartWriting
	 *
	 * @param array $params
	 * @return bool
	 */
	public static function onStartWriting($params)
	{
		$botList = [];
		if (empty($params['CHAT']))
		{
			$botList[] = (int)$params['DIALOG_ID'];
		}
		elseif (!empty($params['RELATION']))
		{
			foreach ($params['RELATION'] as $relation)
			{
				if ($relation['EXTERNAL_AUTH_ID'] === \Bitrix\Im\Bot::EXTERNAL_AUTH_ID)
				{
					$botList[(int)$relation['USER_ID']] = (int)$relation['USER_ID'];
				}
			}
		}

		$result = true;
		foreach ($botList as $botId)
		{
			$className = BotData::getInstance($botId)->getClass();

			if (
				!empty($className)
				&& class_exists($className)
				&& method_exists($className, 'onStartWriting')
			)
			{
				$params['BOT_ID'] = $botId;

				Log::write($params, 'START WRITING');

				$result = call_user_func([$className, 'onStartWriting'], $params);
			}
		}

		return $result;
	}

	/**
	 * Handler for "im:OnSessionVote" event.
	 * @see \CIMMessenger::LinesSessionVote
	 * @see \Bitrix\Imbot\Bot\NetworkBot::onSessionVote
	 *
	 * @param array $params
	 * @return bool
	 */
	public static function onSessionVote($params)
	{
		$botList = [];
		if (empty($params['CHAT']))
		{
			$botList[] = (int)$params['DIALOG_ID'];
		}
		elseif (!empty($params['RELATION']))
		{
			foreach ($params['RELATION'] as $relation)
			{
				if ($relation['EXTERNAL_AUTH_ID'] === \Bitrix\Im\Bot::EXTERNAL_AUTH_ID)
				{
					$botList[(int)$relation['USER_ID']] = (int)$relation['USER_ID'];
				}
			}
		}

		$result = true;
		foreach ($botList as $botId)
		{
			$className = BotData::getInstance($botId)->getClass();

			if (
				!empty($className)
				&& class_exists($className)
				&& method_exists($className, 'onSessionVote')
			)
			{
				$params['BOT_ID'] = $botId;

				Log::write($params, 'SESSION VOTE');

				$result = call_user_func([$className, 'onSessionVote'], $params);
			}
		}

		return $result;
	}
}
