<?php

namespace Bitrix\Im\V2\Chat;

use Bitrix\Disk\Folder;
use Bitrix\Disk\Storage;
use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\V2\Analytics\ChatAnalytics;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Comment\ReplyCounter;
use Bitrix\Im\V2\Message\Delete\DeletionMode;
use Bitrix\Im\V2\Message\Send\MentionService;
use Bitrix\Im\V2\Message\Send\SendingConfig;
use Bitrix\Im\V2\Message\Send\SendingService;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Reading\Reader;
use Bitrix\Im\V2\Relation;
use Bitrix\Im\V2\RelationCollection;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Message;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Pull\Event;

/**
 * Chat for comments
 */
class CommentChat extends GroupChat
{
	protected const LOCK_TIMEOUT = 3;
	protected const EXTRANET_CAN_SEE_HISTORY = true;
	protected const EXTRANET_CAN_SEE_HISTORY_ON_EXPLICIT_ADD = true;
	protected ?Chat $parentChat;
	protected ?Message $parentMessage;
	protected ?int $replyCount = null;

	/**
	 * @return Result<CommentChat|null>
	 */
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

	/**
	 * @return Result<Chat[]>
	 */
	public static function getChatsByMessages(MessageCollection $messages): Result
	{
		$result = new Result();

		$chatIds = static::getIdsByMessages($messages);
		if (empty($chatIds))
		{
			return $result;
		}

		$chats = [];

		foreach ($chatIds as $chatInfo)
		{
			$chat = Chat::getInstance((int)$chatInfo['ID']);
			if ($chat instanceof self)
			{
				$chat->parentMessage = $messages[(int)$chatInfo['PARENT_MID']];
				$chats[] = $chat;
			}
		}

		return $result->setResult($chats);
	}

	protected function getMentionService(SendingConfig $config): MentionService
	{
		return new Message\Send\Mention\CommentMentionService($config);
	}

	/**
	 * @return Result<CommentChat|null>
	 */
	public static function create(Message $message): Result
	{
		$result = new Result();

		// The parent is not checked here: createInternal() reaches ChatFactory, and the tree relation
		// registry refuses an inadmissible pair there with the very same error.
		$isLocked = Application::getConnection()->lock(self::getLockName($message->getId()), self::LOCK_TIMEOUT);
		if (!$isLocked)
		{
			return $result->addError(new ChatError(ChatError::CREATION_ERROR));
		}

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

	public function isAutoJoinEnabled(): bool
	{
		return true;
	}

	public function canUserAutoJoin(?int $userId = null): bool
	{
		return parent::canUserAutoJoin($userId) && $this->getParentChat()->getSelfRelation() !== null;
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

	/**
	 * A discussion lives by the rules of the chat it belongs to, so a rule changed later reaches the
	 * discussions opened before it. One point for the same reason getRole() is one: every consumer of
	 * the format gets the rules and the role agreeing with each other, not the client alone.
	 */
	public function getThreadSettingsChat(): ?Chat
	{
		return $this->getParentChat()->getThreadSettingsChat();
	}

	/**
	 * ALG-01: the single point every write path of a discussion goes through, so none of them is
	 * touched - the legacy bridge of CIMMessenger::Add() with the bots, the commands, the activities
	 * and the imports behind it, the disk integration, the AI assistant and the REST prefilters alike.
	 */
	public function canDo(Action $action, mixed $target = null): bool
	{
		if ($action === Action::Send && !$this->canPostInThread())
		{
			return false;
		}

		return parent::canDo($action, $target);
	}

	/**
	 * The rules are read off the parent, never off the discussion: a rule changed later reaches the
	 * discussions opened before it.
	 */
	private function canPostInThread(): bool
	{
		return $this->getParentChat()->checkThreadPostAccess()->isSuccess();
	}

	/**
	 * The reason a discussion refuses this message, for the one caller that has to show a text for it.
	 * canDo() answers can or cannot and carries no reason, so it is asked here instead.
	 *
	 * Null when the message is allowed, and null when the rules that predate threads refuse it too: a
	 * user who is no member of the chat has always been answered ACCESS_DENIED, and a rule of threads
	 * must not take that answer over. So the reason is named only where it is the deciding one.
	 */
	public function getThreadPostError(): ?Error
	{
		if (!parent::canDo(Action::Send))
		{
			return null;
		}

		$errors = $this->getParentChat()->checkThreadPostAccess()->getErrors();

		return $errors[0] ?? null;
	}

	public function isCounterIncrementAllowed(): bool
	{
		return true;
	}

	public function allowMentionAllChatNotification(): bool
	{
		return false;
	}

	public function onBeforeMentionsChange(Message\Send\Mention\MentionChange $mentionChange): Result
	{
		$parentResult = parent::onBeforeMentionsChange($mentionChange);
		$result = $this->subscribeUsers(true, $mentionChange->addedUserIds, $mentionChange->message->getPrevId());

		return Result::merge($parentResult, $result);
	}

	protected function onAfterMessageSend(Message $message, SendingService $sendingService): void
	{
		$this->replyCount = null;
		$this->subscribe(true, $message->getAuthorId());
		Message\LastMessages::insert($message);

		// counted here and not left to the event: the event formats a clone of this chat
		// (PushFormat -> withContext -> clone), and a clone inherits a warm count but never fills a cold
		// one back, so the analytics below would run the same aggregate a second time on every reply
		$isFirstReply = $this->getReplyCount() === 1 && !$this->isFirstReplyCounted();

		parent::onAfterMessageSend($message, $sendingService);

		if ($isFirstReply)
		{
			// answered here and not inside the analytics job: by the time the job runs the discussion may
			// hold another reply, and the label would be lost or sent twice
			(new ChatAnalytics($this))->withContext($message->getContext())->addFirstReply();
		}
	}

	/**
	 * Whether this discussion has already been counted as started. The number of replies alone cannot
	 * answer that: a complete deletion of the only reply takes it back to zero, and the next reply
	 * written would look like the first one again. Only a portal administrator deletes that completely
	 * - a member of a discussion always deletes softly - but one such deletion is enough to count the
	 * same discussion twice, so the answer is kept with the chat instead of being derived.
	 */
	public function isFirstReplyCounted(): bool
	{
		return (bool)$this->getChatParams()->get(Param\Params::FIRST_REPLY_COUNTED)?->getValue();
	}

	/**
	 * Set once the label of the first reply has really been sent, and not when it was decided to send
	 * it: a discussion whose event never left must stay countable, otherwise a failure of the sending
	 * path would silently cost the metric a row with nothing to show for it.
	 */
	public function markFirstReplyCounted(): void
	{
		$result = $this->getChatParams()->addParamByName(Param\Params::FIRST_REPLY_COUNTED, true);
		if (!$result->isSuccess())
		{
			// A mark that never reached the chat is the double count itself: the event has gone and the
			// next reply written after a complete deletion counts the same discussion a second time
			(new LoggerFactory())->createById('im.Thread.FirstReply')?->error(
				'Failed to mark the first reply of a discussion as counted',
				[
					'chatId' => $this->getId(),
					'errors' => $result->getErrorMessages(),
				],
			);
		}
	}

	public function onAfterMessagesDelete(MessageCollection $messages, DeletionMode $deletionMode): Result
	{
		$this->replyCount = null;

		$result = parent::onAfterMessagesDelete($messages, $deletionMode);

		// Only a complete deletion changes MESSAGE_COUNT of the discussion, so only it leaves the
		// plate of the root post stale. A soft deletion keeps the message and the count with it.
		if ($deletionMode === DeletionMode::Complete)
		{
			$this->markParentMessageAsUpdated($this->getParentChat(), $this->getUsersToNotify()->getUserIds());
		}

		return $result;
	}

	/**
	 * DTO-01: the number the plate of the root post shows. Read off the messages, not off
	 * MESSAGE_COUNT, so that a live session and a history load never name two different numbers.
	 *
	 * Held for the request, and the send path warms it before the event is formatted: the event and
	 * the analytics job behind it ask the same instance and pay for one aggregate between them. The
	 * two points where the message set of a discussion changes drop what is held.
	 */
	public function getReplyCount(): int
	{
		return $this->replyCount ??= ReplyCounter::getForChat((int)$this->getId());
	}

	/**
	 * EVENT-01: the number travels with every event that can change it, so the plate of the root
	 * post grows in a live session instead of waiting for the chat to be loaded again. It sits
	 * beside message_count rather than replacing it: installed clients still read the old key.
	 */
	public function toPullFormat(): array
	{
		$result = parent::toPullFormat();
		$result['replyCount'] = $this->getReplyCount();

		return $result;
	}

	public function filterUsersToMention(array $userIds): array
	{
		return $this->getParentChat()->filterUsersToMention($userIds);
	}

	public function getAllUserIdsForMention(): array
	{
		return $this->getParentChat()->getAllUserIdsForMention();
	}

	public function getPullRecipients(): RelationCollection
	{
		return parent::getPullRecipients()->filterNotifySubscribed();
	}

	public function getUsersToNotify(): RelationCollection
	{
		return parent::getUsersToNotify()->filterNotifySubscribed();
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

		$notifyBlock = !$subscribe;
		$subscriptionChanged = $relation->getNotifyBlock() !== $notifyBlock;
		$relation->setNotifyBlock($notifyBlock)->save();
		$this->sendSubscribePush($subscribe, [$userId]);
		if ($subscriptionChanged)
		{
			$this->sendSubscribersChangedPush([$userId]);
		}

		if (!$subscribe)
		{
			ServiceLocator::getInstance()->get(Reader::class)->readAllInChat($this->getId(), $userId);
		}

		return $result;
	}

	public function subscribeUsers(bool $subscribe = true, array $userIds = [], ?int $lastId = null): Result
	{
		$result = new Result();

		if (empty($userIds))
		{
			return $result;
		}

		$changedUserIds = $subscribe
			? $this->subscribeRelations($userIds, $lastId)
			: $this->unsubscribeRelations($userIds)
		;

		$this->sendSubscribePush($subscribe, $changedUserIds);
		$this->sendSubscribersChangedPush($changedUserIds);

		if (!$subscribe)
		{
			// Same side effect as subscribe(false), in the same order: an unsubscribed user is dropped
			// from the pull recipients of the discussion, so counters left behind would never be
			// cleared for them.
			$reader = ServiceLocator::getInstance()->get(Reader::class);
			foreach ($changedUserIds as $userId)
			{
				$reader->readAllInChat($this->getId(), $userId);
			}
		}

		return $result;
	}

	/**
	 * @param int[] $userIds
	 * @return int[] users whose subscription state has changed
	 */
	private function subscribeRelations(array $userIds, ?int $lastId): array
	{
		$this->addUsers($userIds, new Relation\AddUsersConfig(hideHistory: false, cascadeToParent: false));
		$relations = $this->getRelations();
		$changedUserIds = [];
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
			$changedUserIds[] = $userId;
		}

		$relations->save(true);

		return $changedUserIds;
	}

	/**
	 * Users are never added to the chat here: unsubscribing somebody who does not take part in the
	 * discussion has nothing to change.
	 *
	 * @param int[] $userIds
	 * @return int[] users whose subscription state has changed
	 */
	private function unsubscribeRelations(array $userIds): array
	{
		$relations = $this->getRelations();
		$changedUserIds = [];
		foreach ($userIds as $userId)
		{
			$relation = $relations->getByUserId($userId, $this->getId());
			if ($relation === null || $relation->getNotifyBlock())
			{
				continue;
			}
			$relation->setNotifyBlock(true);
			$changedUserIds[] = $userId;
		}

		$relations->save(true);

		return $changedUserIds;
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

	protected function sendSubscribersChangedPush(array $changedUserIds): void
	{
		if (!Loader::includeModule('pull') || empty($changedUserIds))
		{
			return;
		}

		$recipientIds = $this->getUsersForPush(true, false);
		if (empty($recipientIds))
		{
			return;
		}

		Event::add(
			$recipientIds,
			[
				'module_id' => 'im',
				'command' => 'commentSubscribeChanged',
				'params' => [
					'dialogId' => $this->getDialogId(),
					'messageId' => $this->getParentMessageId(),
					'changedUserIds' => array_map('intval', array_values($changedUserIds)),
				],
				'extra' => \Bitrix\Im\Common::getPullExtra(),
			]
		);
	}

	protected function createDiskFolder(?Storage $storage = null): ?Folder
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

	protected function createRelation(int $userId, Relation\AddUsersConfig $config): Relation
	{
		$notifyBlock = $userId !== $this->getParentMessage()?->getAuthorId();

		return parent::createRelation($userId, $config)->setLastId(0)->setNotifyBlock($notifyBlock);
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

	/**
	 * A post cleared from the channel takes its comments with it. The comment chat gets no border of
	 * its own - the cascade reaches it passes later, in the scheduled cleanup - so it follows the post:
	 * once the post stands behind the border of the channel, every comment is behind it as well.
	 * Reading the comments is refused before this is asked (the access check goes through the post),
	 * so this serves the paths that read no chat, such as a signed link to the file of a comment.
	 */
	public function isMessageBehindHistoryBorder(int $messageId): bool
	{
		return parent::isMessageBehindHistoryBorder($messageId)
			|| $this->getParentChat()->isMessageBehindHistoryBorder($this->getParentMessageId());
	}

	/**
	 * The same rule for a single reader: clearing the channel for oneself moves the border of that reader
	 * in the channel and leaves the comment chats where they are, only unsubscribing from them, so the
	 * comment chat itself holds no border to answer with. This is the reader-scoped half of what the
	 * access check gets through the post ({@see self::checkAccessInternal()}), for the paths that ask
	 * about reachability rather than about access.
	 */
	public function isParentMessageBehindUserHistoryBorder(int $userId): bool
	{
		return $this->getParentChat()->getStartId($userId) > $this->getParentMessageId();
	}

	/**
	 * Both halves at once for a reader of the comments: the post behind the border of the channel, and
	 * the post behind the border this reader has in it.
	 */
	public function isMessageBehindUserHistoryBorder(int $messageId, int $userId): bool
	{
		return parent::isMessageBehindUserHistoryBorder($messageId, $userId)
			|| $this->isParentMessageBehindUserHistoryBorder($userId);
	}

	public function getParentChat(): Chat
	{
		$this->parentChat ??= Chat::getInstance($this->getParentChatId())
			->setContext($this->getContext())
		;

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

	protected function sendMessageUsersAdd(array $usersToAdd, Relation\AddUsersConfig $config): void
	{
		return;
	}

	protected function sendDescriptionMessage(?int $authorId = null): void
	{
		return;
	}

	protected function sendMessageUserDelete(int $userId, Relation\DeleteUserConfig $config): void
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

	protected function sendBanner(?int $authorId = null, bool $force = false): void
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

		if (
			!isset($params['PARENT_MESSAGE'])
			|| !$params['PARENT_MESSAGE'] instanceof Message
			|| $params['PARENT_MESSAGE']->isSystem()
		)
		{
			return $result->addError(new ChatError(ChatError::WRONG_PARENT_MESSAGE));
		}

		$params['PARENT_ID'] = $params['PARENT_CHAT']->getId();
		$params['PARENT_MID'] = $params['PARENT_MESSAGE']->getId();

		$messageAuthorId = $params['PARENT_MESSAGE']->getAuthorId();
		if ($messageAuthorId > 0 && $params['PARENT_CHAT']->getRelationByUserId($messageAuthorId) !== null)
		{
			$params['USERS'][] = $params['PARENT_MESSAGE']->getAuthorId();
		}

		return parent::prepareParams($params);
	}

	protected static function createInternal(Message $message): Result
	{
		$result = new Result();

		$parentChat = $message->getChat();

		$addResult = ChatFactory::getInstance()->withContextUser(0)->addChat([
			'TYPE' => self::IM_TYPE_COMMENT,
			'PARENT_CHAT' => $parentChat,
			'PARENT_MESSAGE' => $message,
			'OWNER_ID' => $parentChat->getAuthorId(),
			'AUTHOR_ID' => $parentChat->getAuthorId(),
		]);

		/**
		 * @var ?static $chat
		 */
		$chat = $addResult->getChat();

		if (!isset($chat) || !$addResult->isSuccess())
		{
			return $addResult;
		}

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

	public static function getIdsByMessages(MessageCollection $messages): array
	{
		$chatId = $messages->getCommonChatId();

		if (!isset($chatId))
		{
			return [];
		}

		$result = ChatTable::query()
			->setSelect(['ID', 'PARENT_MID'])
			->where('PARENT_ID', $chatId)
			->whereIn('PARENT_MID', $messages->getIds())
			->fetchAll()
		;

		return $result;
	}

	protected function sendPushChatCreate(): void
	{
		$params = [
			'id' => $this->getId(),
			'parentChatId' => $this->getParentChatId(),
			'parentMessageId' => $this->getParentMessageId(),
		];

		// the only event of the mechanics that names the parent without carrying a chat block at all,
		// so the parent travels here on its own
		$parentChat = $this->getParentChatPullFormat();
		if ($parentChat !== null)
		{
			$params['parentChat'] = $parentChat;
		}

		Event::add(
			$this->getParentChat()->getRelations()->getUserIds(),
			[
				'module_id' => 'im',
				'command' => 'chatCreate',
				'params' => $params,
				'extra' => \Bitrix\Im\Common::getPullExtra(),
			]
		);
	}

	protected function checkAccessInternal(int $userId): Result
	{
		return $this->getParentMessage()?->checkAccess($userId)
			?? (new Result())->addError(new ChatError(ChatError::ACCESS_DENIED))
		;
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

	protected function needToSendMessageUserDelete(): bool
	{
		return false;
	}
}
