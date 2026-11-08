<?php

namespace Bitrix\Im\V2\Message;

use Bitrix\Im\Text;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\PrivateChat;
use Bitrix\Im\V2\Entity\File\FileCollection;
use Bitrix\Im\V2\Entity\User\NullUser;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Engine\Response\Converter;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Im\V2\Common\ContextCustomer;

class PushFormat
{
	use ContextCustomer;

	protected Message $message;
	private const MAX_CHAT_MESSAGE_TIME = 600;
	private const DND_USER_STATUS = 'dnd';

	public function __construct(Message $message)
	{
		$this->message = $message;
	}

	public function format(): array
	{
		$message = $this->message;
		$chat = $message->getChat()->withContext($message->getContext());
		$chatLegacyFormat = $chat->toPullFormat();
		$users = $this->getUsers();
		$notify = !empty($chat->getRecentSections());

		return [
			'chatId' => $chat->getChatId(),
			'dateLastActivity' => $message->getDateCreate(), // todo test it
			'dialogId' => $chat instanceof PrivateChat ? 0 : $chat->getDialogId(),
			'chat' => $chat instanceof PrivateChat ? [] : [$chat->getId() => $chatLegacyFormat],
			'copilot' => $message->getCopilotData(),
			'lines' => $chat instanceof Chat\OpenLineChat ? $chat->getLineData() : null,
			'multidialog' => $chat->getMultidialogData(),
			'userInChat' => $this->getUserInChat(),
			'userBlockChat' => [$chat->getId() => $chatLegacyFormat['mute_list'] ?? []],
			'users' => $users ?: null,
			'message' => [
				'id' => $message->getMessageId(),
				'templateId' => $message->getUuid(),
				'templateFileId' => $message->getFileUuid(),
				'prevId' => $message->getPrevId(),
				'chatId' => $chat->getChatId(),
				'senderId' => $message->getActionContextUserId(),
				'recipientId' => $chat->getDialogId(),
				'system' => ($message->isSystem() ? 'Y': 'N'),
				'date' => DateTime::createFromTimestamp(time()), // DATE_CREATE
				'text' => Text::parse($message->getMessage()),
				'textLegacy' => Text::parseLegacyFormat($message->getMessage()),
				'params' => $message->getEnrichedParams()->toPullFormat(),
				'counter' => 0,
				'isImportant' => $message->isImportant(),
				'importantFor' => $message->getImportantFor(),
				'additionalEntities' => $this->getAdditionalEntities(),
				'forward' => $message->getForwardInfo(),
				'block' => $message->getBlocksBuilder(),
			],
			'counterType' => $chat->getCounterType(),
			'recentConfig' => $chat->getRecentConfig()->toPullFormat(),
			'files' => $message->getFiles()->toRestFormat(['IDS_AS_KEY' => true]),
			'notify' => $notify,
			'messagesAutoDeleteConfigs' => $chat->getMessageAutoDeleteConfigs()->toRestFormat(),
			'stickers' => $this->getStickers(),
		];
	}

	protected function getStickers(): array
	{
		$sticker = $this->message->getSticker()?->toRestFormat();

		if (empty($sticker))
		{
			return [];
		}

		return [$sticker];
	}

	protected function getUserInChat(): array
	{
		if ($this->message->getChat() instanceof PrivateChat)
		{
			return [];
		}

		$userIds = $this->message->getChat()->getRelations()->getUserIds();

		return [$this->message->getChatId() => array_values($userIds)];
	}

	protected function getUsers(): array
	{
		$pushParams = $this->message->getPushParams();
		$extraParamContext = $pushParams['CONTEXT'] ?? null;

		if ($extraParamContext === Chat::ENTITY_TYPE_LIVECHAT && Loader::includeModule('imopenlines'))
		{
			return $this->getUserForLiveChat();
		}

		$contextUserId = $this->message->getActionContextUserId();
		$contextUser = User::getInstance($contextUserId);

		if ($this->message->getChat() instanceof PrivateChat)
		{
			$users = (array)$this->message->getChat()->getRelations()->getUsers();

			return $this->getUsersLegacyFormat([$contextUser, ...$users]);
		}

		return $this->getUsersLegacyFormat([$contextUser]);
	}

	protected function getUserForLiveChat(): array
	{
		[$lineId, $userId] = explode('|', $this->message->getChat()->getEntityId() ?? '');
		$userCode = 'livechat|' . $lineId . '|' . $this->message->getChatId() . '|' . $userId;
		$operatorInfo = \Bitrix\ImOpenLines\Connector::getOperatorInfo(
			$this->message->getPushParams()['LINE_ID'],
			$this->message->getAuthor()?->getId() ?? 0,
			$userCode
		);

		return [$this->message->getAuthor()?->getId() => $operatorInfo];
	}

	/**
	 * @param User[] $users
	 * @return array
	 */
	protected function getUsersLegacyFormat(array $users): array
	{
		$result = [];
		foreach ($users as $user)
		{
			if (!$user)
			{
				continue;
			}

			$userLegacy = $this->getUserLegacyFormat($user);

			if ($userLegacy)
			{
				$result[$user?->getId()] = $userLegacy;
			}
		}

		return $result;
	}

	protected function getUserLegacyFormat(User $user): ?array
	{
		if ($user instanceof NullUser)
		{
			return null;
		}

		$converter = new Converter(Converter::KEYS | Converter::TO_LOWER | Converter::RECURSIVE);
		$result = $user->getArray();
		$result = $converter->process($result);
		$services = $user->getServices();
		$result['avatar_id'] = $user->getAvatarId();
		$result['phone_device'] = $user->getPhoneDevice();
		$result['services'] = empty($services) ? null : $services;
		$result['profile'] = \CIMContactList::GetUserPath($user->getId());

		return $result;
	}

	protected function getAdditionalEntities(): array
	{
		$message = $this->message;
		$additionalEntitiesAdapter = new \Bitrix\Im\V2\Rest\RestAdapter();
		$additionalPopupData = new \Bitrix\Im\V2\Rest\PopupData([]);

		if ($message->getParams()->isSet(Message\Params::FORWARD_CONTEXT_ID))
		{
			$additionalUserId = (int)$message->getParams()->get(Message\Params::FORWARD_USER_ID)->getValue();
			$additionalPopupData->add(new \Bitrix\Im\V2\Entity\User\UserPopupItem([$additionalUserId]));
		}

		$replyIds = [];
		if ($message->hasReply())
		{
			$replyIds[] = $message->getReplyId();
		}
		$messages = new MessageCollection($replyIds);
		$messages->fillAllForRest();
		$additionalEntitiesAdapter->addEntities($messages);
		$additionalEntitiesAdapter->setAdditionalPopupData($additionalPopupData);
		return $additionalEntitiesAdapter->toRestFormat([
			'WITHOUT_OWN_REACTIONS' => true,
			'MESSAGE_ONLY_COMMON_FIELDS' => true,
			// the payload of an event is common to every recipient of the chat, while the title of the
			// source is personal to the reader: without this exclusion it would reach all of them
			// through additionalEntities.messages[].forward
			'POPUP_DATA_EXCLUDE' => [Message\Forward\ForwardSourcePopupItem::class],
		]);
	}

	public static function formatStartRecordVoice(Chat $chat): array
	{
		$userId = $chat->getContext()->getUserId();
		return [
			'module_id' => 'im',
			'command' => 'startRecordVoice',
			'expiry' => 60,
			'params' => [
				'dialogId' => $chat instanceof PrivateChat ? (string)$userId : $chat->getDialogId(),
				'userId' => $userId,
				'userName' => $chat->getContext()->getUser()->getName()
			],
			'extra' => \Bitrix\Im\Common::getPullExtra()
		];
	}

	/**
	 * The entities of the files repeat the form of the event of the sending, so a client that already knows how to
	 * read them there needs nothing new. FILE_ID is listed among the extra params on purpose: the list is not a white
	 * list, it only fills in what the message no longer has, and without it a composition emptied by the edit would
	 * simply disappear from the event and leave the previous one on the client. The entities travel only with an edit
	 * that changed the composition: after an edit of the text alone the clients already hold the ones they need.
	 */
	public function formatMessageUpdate(bool $compositionUpdated = false): array
	{
		$message = $this->message;

		$params = [
			'id' => $message->getId(),
			'type' => $message->getChat()->getType() === Chat::IM_TYPE_PRIVATE ? 'private' : 'chat',
			'text' => $message->getParsedMessage(),
			'textLegacy' => Text::parseLegacyFormat($message->getMessage()),
			'chatId' => $message->getChatId(),
			'senderId' => $message->getAuthorId(),
			'params' => $message->getEnrichedParams()->toPullFormat([
				'IS_EDITED',
				'URL_ID',
				'ATTACH',
				'DATE_TEXT',
				'DATE_TS',
				Message\Params::FILE_ID,
			]),
			'block' => $message->getBlocksBuilder(),
		];

		// with the feature off the composition cannot change at all, and an edit that left it alone keeps the entities
		// the clients already hold: in both cases the container would be dead weight
		if ($compositionUpdated && Features::isEditMessageFilesAvailable())
		{
			$params['files'] = $this->getFilesOfComposition()->toRestFormat(['IDS_AS_KEY' => true]);
		}

		return [
			'module_id' => 'im',
			'command' => 'messageUpdate',
			'params' => $params,
			'extra' => \Bitrix\Im\Common::getPullExtra()
		];
	}

	/**
	 * Entities of the files the message keeps right now. The edit applies the new composition through
	 * Message::setFiles(), and that writes the params only: a collection loaded earlier in the same edit (the
	 * validation of the message type loads it) would still name the files of the previous composition. The
	 * collection is rebuilt only when it disagrees with the composition, so a message without attachments and a
	 * message whose files were never touched ask the storage nothing.
	 */
	private function getFilesOfComposition(): FileCollection
	{
		$fileIds = $this->message->getFileIds();
		$files = $this->message->getFiles();
		$loadedFileIds = array_values($files->getIds());

		if (array_diff($fileIds, $loadedFileIds) === [] && array_diff($loadedFileIds, $fileIds) === [])
		{
			return $files;
		}

		return FileCollection::initByDiskFilesIds($fileIds, $this->message->getChatId());
	}

	public function validateDataForInform(): Result
	{
		$result = new Result();

		$message = $this->message;
		$toUser = $message->getChat()->getCompanion();
		$toUserStatus = $toUser->getStatus(true);

		if (!($message->getAuthorId() === $this->getContext()->getUserId()))
		{
			$result->addError(new Message\MessageError(Message\MessageError::INFORM_USER_CONTEXT_ERROR));
		}

		if ($message->isViewedByOthers())
		{
			$result->addError(new Message\MessageError(Message\MessageError::INFORM_VIEWED_ERROR));
		}

		$timestampTimeNow = DateTime::createFromTimestamp(time())->getTimestamp();
		if (!($timestampTimeNow - $message->getDateCreate()->getTimestamp() <= self::MAX_CHAT_MESSAGE_TIME))
		{
			$result->addError(new Message\MessageError(Message\MessageError::INFORM_TIMEOUT_ERROR));
		}

		if (!($toUserStatus === self::DND_USER_STATUS))
		{
			$result->addError(new Message\MessageError(Message\MessageError::INFORM_USER_STATUS_ERROR));
		}

		return $result;
	}
}
