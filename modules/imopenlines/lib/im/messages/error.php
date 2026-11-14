<?php
namespace Bitrix\ImOpenLines\Im\Messages;

use Bitrix\ImOpenLines\Im;

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Class Error
 * @package Bitrix\ImOpenLines\Im
 */
class Error
{
	/**
	 * @param $chatId
	 * @param $message
	 * @param string $messageExternalError
	 * @param int $replyId Id of the message the error is about, to quote it with a reply block.
	 * @return bool|int
	 */
	public static function addErrorNotSendChat($chatId, $message, string $messageExternalError = '', int $replyId = 0)
	{
		return self::sendError($chatId, $message, $messageExternalError, $replyId);
	}

	/**
	 * @param $chatId
	 * @param $message
	 * @param string $messageExternalError
	 * @return bool|int
	 */
	public static function addErrorNotDeleteChat($chatId, $message, string $messageExternalError = '')
	{
		return self::sendError($chatId, $message, $messageExternalError);
	}

	/**
	 * @param $chatId
	 * @param $message
	 * @param string $messageExternalError
	 * @param int $replyId Id of the message the error is about, to quote it with a reply block.
	 * @return bool|int
	 */
	protected static function sendError($chatId, $message, string $messageExternalError = '', int $replyId = 0)
	{
		if(!empty($messageExternalError))
		{
			$message .= "[BR][BR]";
			$message .= "------------------------------------------------------\n";
			$message .= $messageExternalError;
			$message .= "\n------------------------------------------------------";
		}

		$fields = [
			'TO_CHAT_ID' => $chatId,
			'MESSAGE' => $message,
			'SYSTEM' => 'Y',
			'URL_PREVIEW' => 'N',
			'NO_SESSION_OL' => 'Y',
		];

		// A quote of the message the error is about: it is not always the last one in the dialog, and
		// naming it inside the text reads worse than the messenger's own reply block.
		if ($replyId > 0)
		{
			$fields['PARAMS'] = ['REPLY_ID' => $replyId];
		}

		return Im::addMessage($fields);
	}
}