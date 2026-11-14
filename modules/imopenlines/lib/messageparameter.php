<?php

namespace Bitrix\Imopenlines;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Im\V2\Message\Param;


class MessageParameter
{
	public const
		CONNECTOR_MID = 'CONNECTOR_MID',
		IMOL_QUOTE_MSG = 'IMOL_QUOTE_MSG',
		IMOL_VOTE = 'IMOL_VOTE',
		IMOL_VOTE_TEXT = 'IMOL_VOTE_TEXT',
		IMOL_VOTE_LIKE = 'IMOL_VOTE_LIKE',
		IMOL_VOTE_DISLIKE = 'IMOL_VOTE_DISLIKE',
		IMOL_VOTE_HEAD = 'IMOL_VOTE_HEAD',
		IMOL_COMMENT_HEAD = 'IMOL_COMMENT_HEAD',
		IMOL_FORM = 'IMOL_FORM',
		IMOL_DATE_CLOSE_VOTE = 'IMOL_DATE_CLOSE_VOTE',
		IMOL_TIME_LIMIT_VOTE = 'IMOL_TIME_LIMIT_VOTE',
		IMOL_VOTE_SID = 'IMOL_VOTE_SID',
		IMOL_VOTE_USER = 'IMOL_VOTE_USER',
		IMOL_VOTE_CHANGES = 'IMOL_VOTE_CHANGES',
		IMOL_SID = 'IMOL_SID',
		IMOL_FORCE_OPERATOR = 'IMOL_FORCE_OPERATOR',
		/**
		 * Marks a send made by the "Message to the open line" robot on behalf of an employee. The text
		 * of the robot carries the NO_SESSION_OL flag of the send itself, but its attachments travel
		 * through \CIMDisk::UploadFileFromDisk(), which builds the fields of the message on its own and
		 * has no way to pass that flag - hence the mark lives in the params.
		 *
		 * Deliberately without the IMOL_ prefix: the filters of Connector::onMessageSend() let only
		 * their allowed fields and the IMOL_/IS_ prefixes through to the client, so a service mark of
		 * one sender stays inside the portal. Its type is deliberately not registered below either: an
		 * unregistered param is read as a string (@see \Bitrix\Im\V2\Message\Params::getType()), which
		 * is all a mark needs, and the typed contract of the module stays as it is.
		 */
		OL_ROBOT_SEND = 'OL_ROBOT_SEND'
	;

	public static function onInitTypes(Event $event): EventResult
	{
		$settings = [
			self::CONNECTOR_MID => [
				'type' => Param::TYPE_STRING_ARRAY,
			],
			// Allow|Disallow to quote message
			self::IMOL_QUOTE_MSG => [
				'type' => Param::TYPE_BOOL,
				'default' => false,
			],
			// Vote value: dislike|like
			self::IMOL_VOTE => [
				'type' => Param::TYPE_STRING,
			],
			self::IMOL_VOTE_TEXT => [
				'type' => Param::TYPE_STRING,
				'default' => '',
				'saveValueFilter' => [\Bitrix\Im\Text::class, 'encodeEmoji'],
				'loadValueFilter' => [\Bitrix\Im\Text::class, 'decodeEmoji'],
			],
			// Vote like button text
			self::IMOL_VOTE_LIKE => [
				'type' => Param::TYPE_STRING,
				'default' => '',
				'saveValueFilter' => [\Bitrix\Im\Text::class, 'encodeEmoji'],
				'loadValueFilter' => [\Bitrix\Im\Text::class, 'decodeEmoji'],
			],
			// Vote dislike button text
			self::IMOL_VOTE_DISLIKE => [
				'type' => Param::TYPE_STRING,
				'default' => '',
				'saveValueFilter' => [\Bitrix\Im\Text::class, 'encodeEmoji'],
				'loadValueFilter' => [\Bitrix\Im\Text::class, 'decodeEmoji'],
			],
			// Vote value by manager
			self::IMOL_VOTE_HEAD => [
				'type' => Param::TYPE_INT,
			],
			// Manager comment
			self::IMOL_COMMENT_HEAD => [
				'type' => Param::TYPE_STRING,
			],
			// Form type: like|welcome
			self::IMOL_FORM => [
				'type' => Param::TYPE_STRING,
			],
			// Not TYPE_DATE_TIME because it is not stored as a timestamp
			self::IMOL_DATE_CLOSE_VOTE => [
				'type' => Param::TYPE_STRING,
			],
			// Vote time limit
			self::IMOL_TIME_LIMIT_VOTE => [
				'type' => Param::TYPE_INT,
			],
			// OL session Id
			self::IMOL_VOTE_SID => [
				'type' => Param::TYPE_INT,
			],
			self::IMOL_VOTE_USER => [
				'type' => Param::TYPE_INT,
			],
			// How many times the client has already changed the mark of this vote request.
			// Internal bookkeeping of the vote limit, the client has no use for it.
			self::IMOL_VOTE_CHANGES => [
				'type' => Param::TYPE_INT,
				'isHidden' => true,
			],
			// OL session Id
			self::IMOL_SID => [
				'type' => Param::TYPE_INT,
			],
			// OL operator Id
			self::IMOL_FORCE_OPERATOR => [
				'type' => Param::TYPE_INT,
			],
		];

		return new EventResult(EventResult::SUCCESS, $settings, 'imopenlines');
	}
}