<?php

namespace Bitrix\Mail\Integration\Im;

use Bitrix\Im\Common;
use Bitrix\Im\Dialog;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Mail\Helper\Message;
use Bitrix\Mail\Integration\Intranet\Secretary;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use CIMMessageParamAttach;
use CIMMessenger;

Loc::loadMessages(__FILE__);

class Chat
{
	/**
	 * @throws LoaderException
	 * @throws SystemException
	 */
	public static function addMailInChat(array $messageData, int $userId, string $dialogId): Result
	{
		$result = new Result();
		if (!Loader::includeModule('im'))
		{
			$result->addError(new Error('create mail chat: failed to load modules'));
		}

		if (empty($messageData['SUBJECT']))
		{
			$messageData['SUBJECT'] = Loc::getMessage(
				'MAIL_CREATE_MAIL_CHAT_EMPTY_SUBJECT',
				['#MESSAGE_ID#' => $messageData['ID']]
			);
		}

		$entityId = Common::isChatId($dialogId) ? Dialog::getChatId($dialogId) : (int) $dialogId;
		$type = Common::isChatId($dialogId) ? Message::ENTITY_TYPE_CHAT_MESSAGE : Message::ENTITY_TYPE_USER_MESSAGE;

		$isAccessProvided =
			Secretary::isAccessProvidedToMessage($messageData['ID'], $messageData['MAILBOX_ID'], $type, $entityId)
			|| Secretary::provideAccessToMessage($messageData['ID'], $type, $entityId, $userId)
		;

		if ($isAccessProvided)
		{
			$message = \Bitrix\Mail\Item\Message::fromArray($messageData);
			$postMailChatDiscussMessageResult = self::postMailChatDiscussMessage($message, $dialogId, $userId);
			if ($postMailChatDiscussMessageResult->isSuccess())
			{
				return $result;
			}

			return $postMailChatDiscussMessageResult;
		}

		$result->addError(new Error('create mail chat: fail to provide access to message'));

		return $result;
	}

	/**
	 * @throws LoaderException
	 */
	public static function postMailChatDiscussMessage(\Bitrix\Mail\Item\Message $message, string $dialogId, int $userId): Result
	{
		$result = new Result();
		if (!Loader::includeModule('im'))
		{
			$result->addError(new Error('post mail welcome message: failed to load modules'));
		}

		$attach = new CIMMessageParamAttach(null, CIMMessageParamAttach::CHAT);
		$attach->AddMessage(Loc::getMessage(
			'MAIL_POST_DISCUSS_MESSAGE_RECEIVED_DATE',
			['#RECEIVED_DATE#' => \FormatDate('j F Y, H:i', $message->getDate()->getTimestamp())]
		));
		$attach->AddMessage(Loc::getMessage(
			'MAIL_POST_DISCUSS_MESSAGE_FROM',
			['#FROM#' => $message->getFrom()]
		));
		$attach->AddMessage(Loc::getMessage(
			'MAIL_POST_DISCUSS_MESSAGE_TO',
			['#TO#' => $message->getTo()]
		));
		$attach->AddMessage(Loc::getMessage(
			'MAIL_POST_DISCUSS_MESSAGE_BODY',
			['#BODY#' => trim(htmlspecialcharsbx(mb_substr($message->getBody(), 0, 200)))]
		));

		$pathToMessage = Common::isChatId($dialogId)
			? Secretary::getMessageUrlForChatMessage($message->getId(), Dialog::getChatId($dialogId))
			: Secretary::getMessageUrlForUserMessage($message->getId(), $dialogId)
		;
		$chatMessageFields = [
			'URL_PREVIEW' => 'N',
			'USER_ID' => $userId,
			'TO_USER_ID' => $dialogId,
			'ATTACH' => $attach,
			'FROM_USER_ID' => $userId,
			'MESSAGE_TYPE' => IM_MESSAGE_CHAT,
			'MESSAGE' => Loc::getMessage(
				'MAIL_POST_DISCUSS_MESSAGE_SUBJECT',
				['#SUBJECT#' => '[url=' . $pathToMessage . ']' . $message->getSubject() . '[/url]']
			),
		];

		if (CIMMessenger::Add($chatMessageFields) === false)
		{
			$result->addError(new Error('post mail welcome message: failed to add message to chat'));
		}

		return $result;
	}

	/**
	 * @throws LoaderException
	 * @throws SystemException
	 */
	public static function createMailChat(array $messageData, int $userId): Result
	{
		$result = new Result();
		if (!Loader::includeModule('im'))
		{
			$result->addError(new Error('create mail chat: failed to load modules'));
		}

		if (empty($messageData['SUBJECT']))
		{
			$messageData['SUBJECT'] = Loc::getMessage(
				'MAIL_CREATE_MAIL_CHAT_EMPTY_SUBJECT',
				['#MESSAGE_ID#' => $messageData['ID']]
			);
		}

		$addChatResult = ChatFactory::getInstance()->addChat([
			'TITLE' => $messageData['SUBJECT'],
			'TYPE' => IM_MESSAGE_CHAT,
			'ENTITY_TYPE' => 'MAIL',
			'ENTITY_ID' => $messageData['ID'],
			'SKIP_ADD_MESSAGE' => 'Y',
			'AUTHOR_ID' => $userId,
			'USERS' => $messageData['USER_IDS']
		]);

		if (!$addChatResult->isSuccess())
		{
			$result->addErrors($addChatResult->getErrors());

			return $result;
		}

		$chatId = $addChatResult->getChatId();

		if (
			$chatId
			&& Secretary::provideAccessToMessage(
				$messageData['ID'],
				Message::ENTITY_TYPE_IM_CHAT,
				$chatId,
				$userId
			)
		)
		{
			$message = \Bitrix\Mail\Item\Message::fromArray($messageData);
			$postWelcomeMessageResult = self::postMailChatWelcomeMessage($message, $chatId, $userId);
			if (!$postWelcomeMessageResult->isSuccess())
			{
				$result->addErrors($postWelcomeMessageResult->getErrors());

				return $result;
			}

			$result->setData(['chatId' => $chatId]);

			return $result;
		}

		$result->addError(new Error('create mail chat: failed to create chat or provide access to message'));
		return $result;
	}

	/**
	 * @throws LoaderException
	 */
	public static function postMailChatWelcomeMessage(\Bitrix\Mail\Item\Message $message, int $chatId, int $userId): Result
	{
		$result = new Result();
		if (!Loader::includeModule('im'))
		{
			$result->addError(new Error('post mail welcome message: failed to load modules'));
		}

		$pathToMessage = Secretary::getMessageUrlForChat($message->getId(), $chatId);
		$entryLinkTitle = '[url=' . $pathToMessage . ']' . $message->getSubject() . '[/url]';
		$chatMessageFields = [
			'USER_ID' => $userId,
			'CHAT_ID' => $chatId,
			'MESSAGE' => Loc::getMessage(
				'MAIL_POST_WELCOME_MESSAGE',
				[
					'#MAIL_TITLE#' => $entryLinkTitle,
				]
			),
		];
		\CIMChat::AddSystemMessage($chatMessageFields);

		return $result;
	}
}