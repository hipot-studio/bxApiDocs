<?php

namespace Bitrix\Call;

use Bitrix\Im\Call\Call;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SiteTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Params;


class CallChatMessage
{
	public static function makeCloudRecordReadyMessage(Call $call, Chat $chat, string $downloadUrl): ?Message
	{
		$phrase =
			self::getPhraseWithCallLink('CALL_CLOUD_RECORDING_READY_MESSAGE', $call->getId(), $chat)
			.' '. self::getMessage('CALL_CLOUD_RECORDING_READY_DOWNLOAD', ['#DOWNLOAD_URL#' => $downloadUrl]);

		$message = new Message();
		$message->setMessage($phrase);
		$message->markAsSystem(true);

		return $message;
	}

	public static function makeCloudRecordPrepareMessage(Call $call, Chat $chat): ?Message
	{
		$message = new Message();
		$message->setMessage(self::getPhraseWithCallLink('CALL_CLOUD_RECORDING_PREPARE_MESSAGE_LINK', $call->getId(), $chat));
		$message->markAsSystem(true);

		return $message;
	}

	public static function makeCloudRecordErrorMessage(Call $call, Chat $chat, string $errorText): ?Message
	{
		return self::makeMessageWithCallLink(
			'CALL_CLOUD_RECORDING_ERROR_MESSAGE',
			$call->getId(),
			$chat,
			['#ERROR#' => $errorText]
		);
	}

	public static function generateOpponentBusyMessage(int $opponentUserId): ?Message
	{
		Loader::includeModule('im');

		Loc::loadMessages($_SERVER["DOCUMENT_ROOT"]. '/bitrix/modules/im/lib/call/integration/chat.php');

		$opponentUser = \Bitrix\Im\User::getInstance($opponentUserId);

		$message = new Message();
		$message->setMessage(
			Loc::getMessage('IM_CALL_INTEGRATION_CHAT_CALL_USER_BUSY_'. ($opponentUser->getGender() === 'F' ? 'F' : 'M'), [
				'#NAME#' => $opponentUser->getFullName()
			])
		);
		$message->setAuthorId($opponentUserId);

		$params = $message->getParams();
		$params->get(Params::NOTIFY)->setValue(true);
		$params->get(Params::COMPONENT_ID)->setValue(NotifyService::MESSAGE_COMPONENT_ID);
		$params->get(Params::COMPONENT_PARAMS)->setValue([
			'MESSAGE_TYPE' => NotifyService::MESSAGE_TYPE_BUSY,
			'INITIATOR_ID' => $opponentUserId,
			'MESSAGE_TEXT' => $message->getMessage(),
		]);

		return $message;
	}

	public static function makeCallStartMessageLink(int $callId, int $chatId): string
	{
		$callStartMessageId = null;
		if ($startMessage = NotifyService::getInstance()->findMessage($chatId, $callId, NotifyService::MESSAGE_TYPE_START))
		{
			$callStartMessageId = $startMessage->getMessageId();
		}

		$linkMess = '';
		if ($callStartMessageId)
		{
			$linkMess = \Bitrix\Call\Library::getChatMessageUrl($chatId, $callStartMessageId);
		}

		return $linkMess;
	}

	protected static function makeMessageWithCallLink(string $phraseCode, int $callId, Chat $chat, array $params = []): Message
	{
		$phrase = self::getPhraseWithCallLink($phraseCode, $callId, $chat, $params);
		$message = new Message();
		$message->setMessage($phrase);
		$message->markAsSystem(true);

		return $message;
	}

	protected static function getPhraseWithCallLink(string $phraseCode, int $callId, Chat $chat, array $params = []): string
	{
		$linkMess = '';
		if (
			$chat instanceof \Bitrix\Im\V2\Chat\GroupChat
			&& $chat->getId() > 0
		)
		{
			$linkMess = self::makeCallStartMessageLink($callId, $chat->getId());
		}
		$params['#CALL_ID#'] = $callId;
		$params['#CALL_START#'] = $linkMess ?: '-';
		$phrase = static::getMessage($phraseCode, $params);
		if (!$linkMess)
		{
			Loader::includeModule('im');
			$phrase = \Bitrix\Im\Text::removeBbCodes($phrase);
		}

		return $phrase;
	}

	protected static function getMessage(string $code, ?array $replace = null): string
	{
		$languageId = Context::getCurrent()->getSite() ? null : SiteTable::getDefaultLanguageId();
		return Loc::getMessage($code, $replace, $languageId) ?? '';
	}
}
