<?php

namespace Bitrix\Im\V2\Chat;

use Bitrix\Disk\Folder;
use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Relation;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Message;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Pull\Event;

/**
 * Chat for comments
 */
class CommentChat extends GroupChat
{
	protected ?Chat $parentChat;
	protected ?Message $parentMessage;

	public static function get(Message $message, bool $createIfNotExists = true): Result
	{
		$result = new Result();
		$chat = null;
		$chatId = static::getIdByMessage($message);
		if ($chatId)
		{
			$chat = Chat::getInstance($chatId);
		}

		if ($chat instanceof self)
		{
			$chat->parentMessage = $message;

			return $result->setResult($chat);
		}

		if (!$createIfNotExists)
		{
			$result->addError(new ChatError(ChatError::NOT_FOUND));

			return $result;
		}

		return static::create($message);
	}

	public static function create(Message $message): Result
	{
		$result = new Result();
		$parentChat = $message->getChat();

		if (!$parentChat instanceof ChannelChat)
		{
			return $result->addError(new ChatError(ChatError::WRONG_PARENT_CHAT));
		}

		Application::getConnection()->lock(self::getLockName($message->getId()));

		$chat = Chat::getInstance(static::getIdByMessage($message));
		if ($chat instanceof self)
		{
			Application::getConnection()->unlock(self::getLockName($message->getId()));
			$chat->parentMessage = $message;

			return $result->setResult($chat);
		}

		$createResult = static::createInternal($message);
		Application::getConnection()->unlock(self::getLockName($message->getId()));

		return $createResult;
	}

	public function join(bool $withMessage = true): Chat
	{
		$this->getParentChat()->join();

		return parent::join(false);
	}

	public function getRole(): string
	{
		if (isset($this->role))
		{
			return $this->role;
		}

		$role = parent::getRole();

		if ($role === self::ROLE_MEMBER)
		{
			$role = $this->getParentChat()->getRole();
		}

		$this->role = $role;

		return $role;
	}

	public function subscribe(bool $subscribe = true, ?int $userId = null): Result
	{
		$userId ??= $this->getContext()->getUserId();
		$result = new Result();

		$relation = $this->getRelationByUserId($userId);

		if ($relation === null)
		{
			return $result->addError(new ChatError(ChatError::ACCESS_DENIED));
		}

		$relation->setNotifyBlock(!$subscribe)->save();
		$this->sendSubscribePush($subscribe, [$userId]);

		if (!$subscribe)
		{
			$this->read();
		}

		return $result;
	}

	protected function filterUsersToAdd(array $userIds): array
	{
		$userIds = parent::filterUsersToAdd($userIds);

		if (empty($userIds))
		{
			return $userIds;
		}

		return $this->getParentChat()->getRelations(['FILTER' => ['USER_ID' => $userIds]])->getUserIds();
	}

	public function subscribeUsers(bool $subscribe = true, array $userIds = [], ?int $lastId = null): Result
	{
		$result = new Result();

		if (empty($userIds))
		{
			return $result;
		}

		$this->addUsers($userIds, [], false);
		$relations = $this->getRelations();
		$subscribedUsers = [];
		foreach ($userIds as $userId)
		{
			$relation = $relations->getByUserId($userId, $this->getId());
			if ($relation === null || !$relation->getNotifyBlock())
			{
				continue;
			}
			$relation->setNotifyBlock(false);
			if ($lastId)
			{
				$relation->setLastId($lastId);
			}
			$subscribedUsers[] = $userId;
		}

		$relations->save(true);
		$this->sendSubscribePush($subscribe, $subscribedUsers);

		return $result;
	}

	protected function sendSubscribePush(bool $subscribe, array $userIds): void
	{
		if (!Loader::includeModule('pull') || empty($userIds))
		{
			return;
		}
		Event::add(
			$userIds,
			[
				'module_id' => 'im',
				'command' => 'commentSubscribe',
				'params' => [
					'dialogId' => $this->getDialogId(),
					'subscribe' => $subscribe,
					'messageId' => $this->getParentMessageId(),
				],
				'extra' => \Bitrix\Im\Common::getPullExtra(),
			]
		);
	}

	protected function createDiskFolder(): ?Folder
	{
		$parentFolder = $this->getParentChat()->getOrCreateDiskFolder();
		if (!$parentFolder)
		{
			return null;
		}

		$folder = $parentFolder->addSubFolder(
			[
				'NAME' => "chat{$this->getId()}",
				'CREATED_BY' => $this->getContext()->getUserId(),
			],
			[],
			true
		);

		if ($folder)
		{
			$this->setDiskFolderId($folder->getId())->save();
		}

		return $folder;
	}

	protected function createRelation(int $userId, bool $hideHistory, array $managersMap, Relation\Reason $reason): Relation
	{
		$notifyBlock = $userId !== $this->getParentMessage()->getAuthorId();

		return parent::createRelation($userId, $hideHistory, $managersMap, $reason)->setLastId(0)->setNotifyBlock($notifyBlock);
	}

	protected function getDefaultType(): string
	{
		return self::IM_TYPE_COMMENT;
	}

	public function setParentChat(?Chat $chat): self
	{
		$this->parentChat = $chat;

		return $this;
	}

	public function getParentChat(): Chat
	{
		$this->parentChat ??= Chat::getInstance($this->getParentChatId());

		return $this->parentChat;
	}

	public function setParentMessage(?Message $message): self
	{
		$this->parentMessage = $message;

		return $this;
	}

	public function getParentMessage(): ?Message
	{
		$this->parentMessage ??= new Message($this->getParentMessageId());

		return $this->parentMessage;
	}

	protected function sendMessageUsersAdd(array $usersToAdd, bool $skipRecent = false): void
	{
		return;
	}

	protected function sendDescriptionMessage(?int $authorId = null): void
	{
		return;
	}

	protected function sendMessageUserDelete(int $userId, bool $skipRecent = false): void
	{
		return;
	}

	protected function sendGreetingMessage(?int $authorId = null)
	{
		$messageText = Loc::getMessage('IM_COMMENT_CREATE_V2');

		\CIMMessage::Add([
			'MESSAGE_TYPE' => $this->getType(),
			'TO_CHAT_ID' => $this->getChatId(),
			'FROM_USER_ID' => 0,
			'MESSAGE' => $messageText,
			'SYSTEM' => 'Y',
			'PUSH' => 'N',
			'SKIP_PULL' => 'Y', // todo: remove
			'SKIP_COUNTER_INCREMENTS' => 'Y',
			'PARAMS' => [
				'NOTIFY' => 'N',
			],
		]);
	}

	protected function sendBanner(?int $authorId = null): void
	{
		return;
	}

	protected static function mirrorDataEntityFields(): array
	{
		$result = parent::mirrorDataEntityFields();
		$result['PARENT_MESSAGE'] = [
			'set' => 'setParentMessage',
			'skipSave' => true,
		];
		$result['PARENT_CHAT'] = [
			'set' => 'setParentChat',
			'skipSave' => true,
		];

		return $result;
	}

	protected function prepareParams(array $params = []): Result
	{
		$result = new Result();

		if (!isset($params['PARENT_CHAT']) || !$params['PARENT_CHAT'] instanceof Chat)
		{
			return $result->addError(new ChatError(ChatError::WRONG_PARENT_CHAT));
		}

		if (!isset($params['PARENT_MESSAGE']) || !$params['PARENT_MESSAGE'] instanceof Message)
		{
			return $result->addError(new ChatError(ChatError::WRONG_PARENT_MESSAGE));
		}

		$params['PARENT_ID'] = $params['PARENT_CHAT']->getId();
		$params['PARENT_MID'] = $params['PARENT_MESSAGE']->getId();
		$params['USERS'][] = $params['PARENT_MESSAGE']->getAuthorId();

		return parent::prepareParams($params);
	}

	protected static function createInternal(Message $message): Result
	{
		$result = new Result();

		$parentChat = $message->getChat();

		$addResult = ChatFactory::getInstance()->addChat([
			'TYPE' => self::IM_TYPE_COMMENT,
			'PARENT_CHAT' => $parentChat,
			'PARENT_MESSAGE' => $message,
			'OWNER_ID' => $parentChat->getAuthorId(),
			'AUTHOR_ID' => $parentChat->getAuthorId(),
		]);

		if (!$addResult->isSuccess())
		{
			return $addResult;
		}

		/** @var static $chat */
		$chat = $addResult->getResult()['CHAT'];
		$chat->parentMessage = $message;
		$chat->sendPushChatCreate();

		return $result->setResult($chat);
	}

	protected static function getIdByMessage(Message $message): int
	{
		$row = ChatTable::query()
			->setSelect(['ID'])
			->where('PARENT_ID', $message->getChatId())
			->where('PARENT_MID', $message->getId())
			->fetch() ?: []
		;

		return (int)($row['ID'] ?? 0);
	}

	protected function sendPushChatCreate(): void
	{
		Event::add(
			$this->getParentChat()->getRelations()->getUserIds(),
			[
				'module_id' => 'im',
				'command' => 'chatCreate',
				'params' => [
					'id' => $this->getId(),
					'parentChatId' => $this->getParentChatId(),
					'parentMessageId' => $this->getParentMessageId(),
				],
				'extra' => \Bitrix\Im\Common::getPullExtra(),
			]
		);
	}

	protected function checkAccessWithoutCaching(int $userId): bool
	{
		return $this->getParentMessage()?->hasAccess() ?? false;
	}

	protected function addIndex(): Chat
	{
		return $this;
	}

	protected function updateIndex(): Chat
	{
		return $this;
	}

	protected static function getLockName(int $messageId): string
	{
		return 'com_create_' . $messageId;
	}
}
