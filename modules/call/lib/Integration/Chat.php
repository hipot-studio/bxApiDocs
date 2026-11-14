<?php

namespace Bitrix\Call\Integration;

use Bitrix\Call\Background\JobDispatcher;
use Bitrix\Call\Call;
use Bitrix\Call\CallUser;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\Settings;
use Bitrix\Call\Signaling;
use Bitrix\Im\Common;
use Bitrix\Im\Dialog;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Im\V2\Chat\NullChat;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\UserTable;

\Bitrix\Main\Loader::includeModule('im');

/**
 * @internal
 */
class Chat extends AbstractEntity
{
	protected $chatId;
	public $chatFields;
	protected $chatUsers = [];
	protected array $pendingMissedCallPushes = [];

	const MUTE_MESSAGE = true;

	private const REAL_USER_COUNT = 'REAL_USER_COUNT';

	public function __construct(Call $call, $entityId)
	{
		parent::__construct($call, $entityId);

		if(Common::isChatId($entityId) || (int)$entityId > 0)
		{
			$chatId = \Bitrix\Im\Dialog::getChatId($entityId, $this->initiatorId);
		}
		else
		{
			throw new ArgumentException("Invalid chat id {$entityId}");
		}

		$chat = \Bitrix\Im\V2\Chat::getInstance($chatId);

		if (empty($userId))
		{
			$params['USER_ID'] = $this->initiatorId;
		}

		if (!$chat instanceof NullChat)
		{
			$this->chatFields = $this->getChatFields($chat);
		}
		$this->chatUsers = self::getRealUserIdsInChat($chatId, $call->getInitiatorId());
		$this->chatId = $chatId;
	}

	/**
	 * The single owner of "who counts as a participant of a call in this chat": the members of the
	 * chat plus the initiator, forced into the composition because an open chat lets one start a
	 * call without being a member of it.
	 *
	 * @return list<numeric-string>
	 */
	public static function getRealUserIdsInChat(int $chatId, int $forcedUserId): array
	{
		$memberIds = \Bitrix\Im\V2\Chat::getInstance($chatId)->getRelationProvider()->getAllMemberIds();
		$memberIds[$forcedUserId] = $forcedUserId;

		return self::getActiveRealUserIds($memberIds);
	}

	/**
	 * The size of the composition, counted by the database: a caller that needs the number only
	 * must not pay for reading the whole membership, so nothing is materialized. Unlike the list
	 * projection, this one reads the raw non-hidden relations of the chat, not the membership
	 * Im\V2\Chat exposes after filtering by access to the parent chat.
	 * The forced user is answered for apart, in the second branch of the same statement, because
	 * they may have no relation to the chat at all; the aggregate leaves them out, so a member is
	 * never counted twice.
	 */
	public static function getRealUserCountInChat(int $chatId, int $forcedUserId): int
	{
		$counts = self::queryRealMemberCount($chatId, $forcedUserId)
			->unionAll(self::queryRealUserCount($forcedUserId))
			->fetchAll()
		;

		return (int)array_sum(array_column($counts, self::REAL_USER_COUNT));
	}

	/**
	 * The id projection of the participant condition.
	 *
	 * @param array<int|numeric-string> $userIds
	 * @return list<numeric-string>
	 */
	public static function getActiveRealUserIds(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$activeRealUsers = UserTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $userIds)
			->where(self::getActiveRealUserFilter())
			->fetchAll()
		;

		return array_column($activeRealUsers, 'ID');
	}

	private static function queryRealMemberCount(int $chatId, int $excludedUserId): Query
	{
		return RelationTable::query()
			->where('CHAT_ID', $chatId)
			->where('IS_HIDDEN', false)
			->whereNot('USER_ID', $excludedUserId)
			->where(self::getActiveRealUserFilter('USER.'))
			->addSelect(new ExpressionField(self::REAL_USER_COUNT, 'COUNT(DISTINCT %s)', 'USER_ID'))
		;
	}

	private static function queryRealUserCount(int $userId): Query
	{
		return UserTable::query()
			->where('ID', $userId)
			->where(self::getActiveRealUserFilter())
			->addSelect(new ExpressionField(self::REAL_USER_COUNT, 'COUNT(%s)', 'ID'))
		;
	}

	/**
	 * The single owner of the participant condition: bots and the other external account types are
	 * left out, except the two the call authorizes itself. Every projection reads it through the
	 * prefix of the entity its query starts from, so the condition itself exists once.
	 */
	private static function getActiveRealUserFilter(string $fieldPrefix = ''): ConditionTree
	{
		return Query::filter()
			->where($fieldPrefix . 'ACTIVE', true)
			->where(Query::filter()
				->logic(ConditionTree::LOGIC_OR)
				->where($fieldPrefix . 'IS_REAL_USER', true)
				->whereIn($fieldPrefix . 'EXTERNAL_AUTH_ID', [
					\Bitrix\Call\Rest\Auth::AUTH_TYPE,
					\Bitrix\Im\V2\Entity\User\UserGuest::AUTH_ID,
				])
			)
		;
	}

	/**
	 * Returns associated entity type.
	 *
	 * @return string
	 */
	public function getEntityType()
	{
		return EntityType::CHAT;
	}

	public function getEntityId($currentUserId = 0)
	{
		if($this->chatFields['message_type'] != IM_MESSAGE_PRIVATE || $currentUserId == 0)
		{
			return $this->entityId;
		}

		return $this->call->getInitiatorId() == $currentUserId ? $this->entityId : $this->call->getInitiatorId();
	}

	public function getChatId()
	{
		return $this->chatId;
	}

	/**
	 * Returns list of users in the chat
	 *
	 * @return array
	 */
	public function getUsers()
	{
		return $this->chatUsers;
	}

	/**
	 * Returns true is user has access to the associated chat and false otherwise.
	 *
	 * @param int $userId
	 * @return bool
	 */
	public function checkAccess(int $userId): bool
	{
		if (Common::isChatId($this->entityId))
		{
			return Dialog::hasAccess($this->entityId, $userId);
		}

		// one-to-one dialog
		return ($userId === (int)$this->entityId || $userId === (int)$this->initiatorId);
	}

	/**
	 * Returns true is user can call users in the associated chat and false otherwise.
	 *
	 * @param int $userId
	 * @return bool
	 */
	public function canStartCall(int $userId): bool
	{
		if (Common::isChatId($this->entityId))
		{
			return Dialog::hasAccess($this->entityId, $userId);
		}

		return true;
	}

	/**
	 * Returns chat owner id.
	 * @return int|null
	 */
	public function getOwnerId(): ?int
	{
		if (!empty($this->chatFields['owner']))
		{
			return (int)$this->chatFields['owner'];
		}

		return null;
	}

	/**
	 * Returns list of the chat managers.
	 * @return int[]
	 */
	public function getManagerIds(): array
	{
		if (!empty($this->chatFields['manager_list']))
		{
			return array_map('intVal', $this->chatFields['manager_list']);
		}

		return [];
	}

	/**
	 * Returns associated entity name.
	 *
	 * @param int $currentUserId Id of the user.
	 * @return string|false
	 */
	public function getName($currentUserId)
	{
		if (!$this->chatFields)
		{
			return false;
		}

		if ($this->chatFields['message_type'] === IM_MESSAGE_PRIVATE && count($this->chatUsers) === 2)
		{
			return \Bitrix\Im\User::getInstance($this->getEntityId($currentUserId))->getFullName();
		}
		if ($this->chatFields['message_type'] !== IM_MESSAGE_PRIVATE)
		{
			return $this->chatFields['name'];
		}

		return false;
	}

	public function getAvatar($currentUserId)
	{
		if (!$this->chatFields)
		{
			return false;
		}

		if ($this->chatFields['message_type'] === IM_MESSAGE_PRIVATE && count($this->chatUsers) === 2)
		{
			return \Bitrix\Im\User::getInstance($this->getEntityId($currentUserId))->getAvatarHr();
		}
		if ($this->chatFields['message_type'] !== IM_MESSAGE_PRIVATE)
		{
			return $this->chatFields['avatar'];
		}

		return false;
	}

	public function getAvatarColor($currentUserId)
	{
		if (!$this->chatFields)
		{
			return false;
		}

		if ($this->chatFields['message_type'] === IM_MESSAGE_PRIVATE && count($this->chatUsers) === 2)
		{
			return \Bitrix\Im\User::getInstance($this->getEntityId($currentUserId))->getColor();
		}
		if ($this->chatFields['message_type'] !== IM_MESSAGE_PRIVATE)
		{
			return $this->chatFields['color'];
		}

		return false;
	}

	public function isPrivateChat(): bool
	{
		return $this->chatFields && $this->chatFields['message_type'] === IM_MESSAGE_PRIVATE;
	}

	public function onUserAdd($userId): bool
	{
		if (!$this->canExtendChat())
		{
			return false;
		}
		if ($this->chatFields['message_type'] == IM_MESSAGE_PRIVATE)
		{
			$users = $this->chatUsers;
			$users[] = $userId;

			$addResult = ChatFactory::getInstance()->addChat([
				'USERS' => $users,
				'SKIP_ANALYTICS' => 'Y',
			]);
			if (!$addResult->isSuccess())
			{
				return false;
			}
			$chatId = $addResult->getChatId();
			if (!$chatId)
			{
				return false;
			}

			if ($this->call)
			{
				$this->call->setAssociatedEntity(static::getEntityType(), 'chat'.$chatId);
				// todo: remove when the calls are supported in the mobile
				if ($this->call->getAssociatedEntity())
				{
					$this->call->getAssociatedEntity()->onCallCreate();
				}
			}
		}
		else
		{
			$chat = new \CIMChat();
			$chatId = \Bitrix\Im\Dialog::getChatId($this->getEntityId());
			if (
				$chatId
				&& $chat->addUser($chatId, $userId)
				&& !in_array($userId, $this->chatUsers)
			)
			{
				$this->chatUsers[] = $userId;
			}
		}

		return true;
	}

	public function onUsersAdd(array $userIds): bool
	{
		if (!$this->canExtendChat())
		{
			return false;
		}

		$userIds = array_values(array_unique(array_map('intval', $userIds)));
		if ($userIds === [])
		{
			return true;
		}

		if ($this->chatFields['message_type'] == \IM_MESSAGE_PRIVATE)
		{
			$addResult = ChatFactory::getInstance()->addChat([
				'USERS' => array_values(array_unique(array_merge($this->chatUsers, $userIds))),
				'SKIP_ANALYTICS' => 'Y',
			]);
			if (!$addResult->isSuccess())
			{
				return false;
			}
			$newChatId = $addResult->getChatId();
			if (!$newChatId)
			{
				return false;
			}

			if ($this->call)
			{
				$this->call->setAssociatedEntity(static::getEntityType(), 'chat' . $newChatId);
				// todo: remove when the calls are supported in the mobile
				$this->call->getAssociatedEntity()?->onCallCreate();
			}

			return true;
		}

		$chatId = \Bitrix\Im\Dialog::getChatId($this->getEntityId());
		if (!$chatId)
		{
			return false;
		}

		$chat = new \CIMChat();
		if (!$chat->addUser($chatId, $userIds))
		{
			return false;
		}

		foreach ($userIds as $userId)
		{
			if (!in_array($userId, $this->chatUsers, true))
			{
				$this->chatUsers[] = $userId;
			}
		}

		return true;
	}

	public function onExistingUsersInvite($userIds): bool
	{
		if (isset($this->chatFields['message_type']) && $this->chatFields['message_type'] === IM_MESSAGE_PRIVATE)
		{
			return true;
		}

		if (!$this->canExtendChat())
		{
			return false;
		}

		$chat = new \CIMChat();
		$chatId = \Bitrix\Im\Dialog::getChatId($this->getEntityId());

		return $chat->addUser($chatId, $userIds);
	}

	public function onStateChange($state, $prevState): void
	{
		$initiatorId = $this->call->getInitiatorId();
		//$initiator = \Bitrix\Im\User::getInstance($initiatorId);
		if ($state === Call::STATE_INVITING && $prevState === Call::STATE_NEW)
		{
			// todo: return the call method when the calls are supported in the mobile
			//$this->sendMessagesCallStart();
		}
		elseif ($state === Call::STATE_FINISHED)
		{
			// Silent clean-up of a stuck call: no chat notifications.
			if ($this->call->isSilentFinish())
			{
				return;
			}

			$message = Loc::getMessage("IM_CALL_INTEGRATION_CHAT_CALL_FINISHED_V2", [
				'#CALL_DURATION#' => $this->getCallDuration(),
			]);
			$mute = true;
			$skipCounterInc = true;

			$userIds = array_values(array_filter($this->call->getUsers(), function($userId) use ($initiatorId)
			{
				return $userId != $initiatorId;
			}));

			$componentParams = [
				'MESSAGE_TYPE' => 'FINISH',
				'CALL_ID' => $this->call->getId(),
				'INITIATOR_ID' => $this->call->getActionUserId(),
			];

			if ($this->call instanceof \Bitrix\Call\Call\PlainCall)
			{
				$otherUser = \Bitrix\Im\User::getInstance($userIds[0]);
				$otherUserState = $this->call->getUser($userIds[0]) ? $this->call->getUser($userIds[0])->getState() : '';

				if ($otherUserState == CallUser::STATE_DECLINED)
				{
					$componentParams['MESSAGE_TYPE'] = 'DECLINED';
					$message = Loc::getMessage("IM_CALL_INTEGRATION_CHAT_CALL_USER_DECLINED_V2_" . $otherUser->getGender(), [
						'#NAME#' => $otherUser->getFullName(false)
					]);
				}
				elseif ($otherUserState == CallUser::STATE_BUSY)
				{
					/*
					$componentParams['MESSAGE_TYPE'] = 'BUSY';
					$message = Loc::getMessage("IM_CALL_INTEGRATION_CHAT_CALL_USER_BUSY_" . $otherUser->getGender(), [
						'#NAME#' => $otherUser->getFullName(false)
					]);
					$mute = false;
					$skipCounterInc = false;
					*/
					$message = '';
				}
				elseif ($otherUserState == CallUser::STATE_CALLING || $otherUserState == CallUser::STATE_UNAVAILABLE)
				{
					$callDurationSec = $this->call->getDuration();
					$invitePeriodSec = (int)(Settings::getCallInvitePeriod() / 1000);

					if ($invitePeriodSec > 0 && $callDurationSec >= $invitePeriodSec)
					{
						$componentParams['MESSAGE_TYPE'] = 'MISSED';
						$message = Loc::getMessage("IM_CALL_INTEGRATION_CHAT_CALL_MISSED", [
							'#NAME#' => $otherUser->getFullName(false)
						]);
					}
					else
					{
						$componentParams['MESSAGE_TYPE'] = 'CANCELLED';
						$message = Loc::getMessage("IM_CALL_INTEGRATION_CHAT_CALL_CANCELLED");
					}
					$mute = false;
					$skipCounterInc = false;
				}
			}

			if ($message)
			{
				$componentParams['MESSAGE_TEXT'] = $message;
				$messageId = $this->sendMessage($message, $mute, $skipCounterInc, $componentParams);

				// Same sender resolution as FROM_USER_ID in sendMessage().
				$fromUserId = isset($componentParams['INITIATOR_ID'])
					? (int)$componentParams['INITIATOR_ID']
					: (int)$initiatorId
				;

				// Pushes are only collected here and sent on call:onCallFinished:
				// at this point the callee is still CALLING and the missed call
				// is not counted yet, so a push sent now would take a stale badge
				// in the synchronous pull mode.
				if (
					in_array($componentParams['MESSAGE_TYPE'], ['MISSED', 'CANCELLED'], true)
					&& !empty($userIds[0])
					&& $messageId
				)
				{
					$this->pendingMissedCallPushes[] = [
						'recipientId' => (int)$userIds[0],
						'fromUserId' => $fromUserId,
						'messageId' => (int)$messageId,
					];
				}
				elseif (
					$componentParams['MESSAGE_TYPE'] === 'FINISH'
					&& $this->call->getType() === Call::TYPE_INSTANT
					&& $messageId
				)
				{
					// Instant call finished without the MISSED/CANCELLED message (group chat,
					// or a private chat served by a non-plain call): the chat gets only the muted
					// FINISH message, so the missed-call notification is the only way for Android
					// to keep the icon badge after the incoming-call one is revoked.
					// Recipients mirror the finish-time "missed" call log mapping:
					// users still ringing or unavailable when the call ended.
					$missedUserIds = array_values(array_filter($userIds, function ($userId) {
						$userState = $this->call->getUser($userId)?->getState();

						return $userState === CallUser::STATE_CALLING || $userState === CallUser::STATE_UNAVAILABLE;
					}));

					if ($missedUserIds && $this->isPrivateChat())
					{
						$this->pendingMissedCallPushes[] = [
							'recipientId' => (int)$missedUserIds[0],
							'fromUserId' => $fromUserId,
							'messageId' => (int)$messageId,
						];
					}
					elseif ($missedUserIds)
					{
						$this->pendingMissedCallPushes[] = [
							'recipientIds' => $missedUserIds,
							'messageId' => (int)$messageId,
						];
					}
				}
			}
		}
	}

	public function onCallCreate(): bool
	{
		$this->sendMessagesCallStart();

		return true;
	}

	public function sendMessagesCallStart(): void
	{
		$message = Loc::getMessage("IM_CALL_INTEGRATION_CHAT_CALL_STARTED_V2", [
			"#ID#" => $this->call->getId()
		]);
		$componentParams = [
			'MESSAGE_TYPE' => 'START', /** @see \Bitrix\Call\NotifyService::MESSAGE_TYPE_START */
			'CALL_ID' => $this->call->getId(),
			'MESSAGE_TEXT' => $message,
		];

		$this->sendMessage($message, self::MUTE_MESSAGE, true, $componentParams);
	}

	/**
	 * Work without an outcome: sendMessage() below answers with an id or false, and nobody reads
	 * what this send ended with.
	 */
	public function sendMessageDeferred($message, $muted = false, $skipCounterInc = false, $componentParams = [])
	{
		JobDispatcher::getInstance()->enqueue([$this, 'sendMessage'], [$message, $muted, $skipCounterInc, $componentParams]);
	}

	public function isBroadcast()
	{
		return
			$this->chatFields['entity_type'] === \Bitrix\Im\Alias::ENTITY_TYPE_VIDEOCONF
			&& $this->chatFields['entity_data_1'] === 'BROADCAST';
	}

	/**
	 * Sends a service message to the chat.
	 *
	 * @return int|false Id of the added message or false on failure.
	 */
	public function sendMessage($message, $muted = false, $skipCounterInc = false, $componentParams = [])
	{
		$initiator = $this->getCall()->getInitiatorId();
		if (isset($componentParams['INITIATOR_ID']))
		{
			$initiator = $componentParams['INITIATOR_ID'];
		}

		$chatId = $this->call->getChatId();
		if (!empty($this->call->getParentId()))
		{
			//todo: Remove it
			$chatId = \Bitrix\Im\Dialog::getChatId($this->getEntityId());
		}

		return \CIMMessenger::add([
			'WAIT_FULL_EXECUTION' => 'N',
			'TO_CHAT_ID' => $chatId,
			'MESSAGE_TYPE' => $this->isPrivateChat() ? IM_MESSAGE_PRIVATE : IM_MESSAGE_CHAT,
			'FROM_USER_ID' => $initiator,
			'MESSAGE' => $message,
			'PUSH' => 'N',
			'SKIP_COUNTER_INCREMENTS' => $skipCounterInc ? 'Y' : 'N',
			'PARAMS' => [
				'NOTIFY' => $muted ? 'N': 'Y',
				'COMPONENT_ID' => 'CallMessage', /** @see \Bitrix\Call\NotifyService::MESSAGE_COMPONENT_ID */
				'COMPONENT_PARAMS' => $componentParams,
			]
		]);
	}

	/**
	 * Sends the missed-call pushes collected in onStateChange().
	 * Called on call:onCallFinished: by that moment Call::finish() has moved
	 * the callees out of CALLING and the missed-call counter is updated,
	 * so the badge computed at send time is correct even when the pull module
	 * sends pushes synchronously (BX_CHECK_AGENT_START without BX_WITH_ON_AFTER_EPILOG).
	 */
	public function sendPendingMissedCallPushes(): void
	{
		$pendingPushes = $this->pendingMissedCallPushes;
		$this->pendingMissedCallPushes = [];

		foreach ($pendingPushes as $pendingPush)
		{
			if (isset($pendingPush['recipientIds']))
			{
				$this->sendMissedCallGroupPush($pendingPush['recipientIds'], $pendingPush['messageId']);
			}
			else
			{
				$this->sendMissedCallPush($pendingPush['recipientId'], $pendingPush['fromUserId'], $pendingPush['messageId']);
			}
		}
	}

	/**
	 * Sends a visible missed-call mobile push to the callee.
	 * Apple devices are filtered out: they handle missed calls natively via CallKit.
	 * Any push failure must not break the call finish flow.
	 */
	protected function sendMissedCallPush(int $recipientId, int $fromUserId, int $messageId): void
	{
		if ($recipientId <= 0 || !\Bitrix\Main\Loader::includeModule('pull'))
		{
			return;
		}

		try
		{
			$fromUser = \Bitrix\Im\User::getInstance($fromUserId);
			$avatarUrl = (string)$fromUser->getAvatar();
			if ($avatarUrl !== '' && mb_strpos($avatarUrl, 'http') !== 0)
			{
				$avatarUrl = Common::getPublicDomain() . $avatarUrl;
			}

			// Resolve the phrase in the language of the only push recipient,
			// it may differ from the language of the current request context.
			$recipientLang = \Bitrix\Im\V2\Entity\User\User::getInstance($recipientId)->getLanguageId() ?: null;

			\Bitrix\Pull\Push::add(
				[$recipientId],
				self::buildMissedCallPushPayload(
					$fromUserId,
					$messageId,
					(string)$fromUser->getFullName(false),
					$avatarUrl,
					$this->resolveMissedCallPushText($recipientLang)
				)
			);
		}
		catch (\Throwable $e)
		{
			// Push delivery is best-effort: a failure must not break the call finish flow.
			Logger::getInstance()->warning(
				"Chat::sendMissedCallPush: missed call push send failed: {$e->getMessage()} (code: {$e->getCode()})."
				. " RecipientId: {$recipientId}"
			);
		}
	}

	/**
	 * Sends the visible missed-call mobile push to the group chat members
	 * who did not answer the call. The notification is styled as a regular
	 * group chat message push, so it opens the chat and is revoked by reading it.
	 * Apple devices are filtered out: they handle missed calls natively via CallKit.
	 * Any push failure must not break the call finish flow.
	 */
	protected function sendMissedCallGroupPush(array $recipientIds, int $messageId): void
	{
		if (empty($recipientIds) || !\Bitrix\Main\Loader::includeModule('pull'))
		{
			return;
		}

		try
		{
			// Same title and avatar processing as the regular group message push:
			// the pull-format title is html-encoded, the avatar may be a blank placeholder.
			$chatTitle = mb_substr(\htmlspecialcharsback((string)($this->chatFields['name'] ?? '')), 0, 32);
			$chatType = (string)($this->chatFields['type'] ?? 'chat');
			$chatTypeLetter = (string)($this->chatFields['message_type'] ?? IM_MESSAGE_CHAT);

			$avatarUrl = (string)($this->chatFields['avatar'] ?? '');
			if ($avatarUrl === '/bitrix/js/im/images/blank.gif')
			{
				$avatarUrl = '';
			}
			elseif ($avatarUrl !== '' && mb_strpos($avatarUrl, 'http') !== 0)
			{
				$avatarUrl = Common::getPublicDomain() . $avatarUrl;
			}

			// Resolve the phrase per recipient language: members of a group chat
			// may use different interface languages. Languages are loaded in bulk:
			// a per-recipient user instance would cost a separate query per recipient.
			$recipientIds = array_values(array_unique(array_map('intval', $recipientIds)));
			$recipientLanguages = array_fill_keys($recipientIds, '');
			foreach (array_chunk($recipientIds, 300) as $recipientIdChunk)
			{
				$userResult = UserTable::query()
					->setSelect(['ID', 'LANGUAGE_ID'])
					->whereIn('ID', $recipientIdChunk)
					->exec()
				;
				while ($user = $userResult->fetch())
				{
					$recipientLanguages[(int)$user['ID']] = (string)($user['LANGUAGE_ID'] ?? '');
				}
			}

			$recipientsByLang = [];
			foreach ($recipientLanguages as $recipientId => $recipientLang)
			{
				$recipientsByLang[$recipientLang][] = $recipientId;
			}

			foreach ($recipientsByLang as $lang => $langRecipientIds)
			{
				\Bitrix\Pull\Push::add(
					$langRecipientIds,
					self::buildMissedCallGroupPushPayload(
						(int)$this->chatId,
						$chatType,
						$chatTypeLetter,
						$messageId,
						$chatTitle,
						$avatarUrl,
						$this->resolveMissedCallPushText($lang !== '' ? $lang : null)
					)
				);
			}
		}
		catch (\Throwable $e)
		{
			// Push delivery is best-effort: a failure must not break the call finish flow.
			Logger::getInstance()->warning(
				"Chat::sendMissedCallGroupPush: missed call push send failed: {$e->getMessage()} (code: {$e->getCode()})."
				. " ChatId: {$this->chatId}"
			);
		}
	}

	/**
	 * Resolves the missed-call push text in the recipient language with a fallback
	 * to the current context language, so the push never goes out empty.
	 */
	protected function resolveMissedCallPushText(?string $languageId): string
	{
		$pushText = (string)Loc::getMessage('IM_CALL_INTEGRATION_CHAT_CALL_MISSED_PUSH', null, $languageId);
		if ($pushText === '')
		{
			// The phrase has no translation for the recipient language yet:
			// fall back to the current context language instead of an empty push text.
			$pushText = (string)Loc::getMessage('IM_CALL_INTEGRATION_CHAT_CALL_MISSED_PUSH');
		}

		return $pushText;
	}

	/**
	 * Builds the payload for the missed-call mobile push.
	 * Pure function of its arguments: no I/O, safe to unit test.
	 * The push text is resolved by the caller in the recipient language.
	 * The badge key is omitted on purpose: \CPushManager::prepareSend fills it
	 * from the mobile counter aggregator.
	 */
	public static function buildMissedCallPushPayload(int $fromUserId, int $messageId, string $senderName, string $avatarUrl, string $pushText): array
	{
		return [
			'module_id' => 'im',
			'push' => [
				'type' => 'message',
				'tag' => 'IM_MESS_' . $fromUserId,
				'sub_tag' => 'IM_MESS',
				'app_id' => 'Bitrix24',
				'message' => $pushText,
				'send_immediately' => 'Y',
				'advanced_params' => [
					'group' => 'im_message',
					'avatarUrl' => $avatarUrl,
					'senderName' => $senderName,
					'senderMessage' => $pushText,
					'filterCallback' => [Signaling::class, 'filterPushesForApple'],
				],
				'params' => [
					'TAG' => 'IM_MESS_' . $fromUserId,
					'CATEGORY' => 'ANSWER',
					'URL' => SITE_DIR . 'mobile/ajax.php?mobile_action=im_answer',
					'PARAMS' => [
						'RECIPIENT_ID' => $fromUserId,
						'MESSAGE_ID' => $messageId,
					],
				],
			],
		];
	}

	/**
	 * Builds the payload for the missed-call mobile push in a group chat.
	 * Pure function of its arguments: no I/O, safe to unit test.
	 * Mirrors the regular group message push (tag IM_CHAT_{chatId}, chat identity
	 * in the notification header), so tapping it opens the chat and reading
	 * the chat revokes the notification. The push text is resolved by the caller
	 * in the recipient language. The badge key is omitted on purpose:
	 * \CPushManager::prepareSend fills it from the mobile counter aggregator.
	 */
	public static function buildMissedCallGroupPushPayload(
		int $chatId,
		string $chatType,
		string $chatTypeLetter,
		int $messageId,
		string $chatTitle,
		string $avatarUrl,
		string $pushText
	): array
	{
		return [
			'module_id' => 'im',
			'push' => [
				'type' => $chatType === 'open' ? 'openChat' : $chatType,
				'tag' => 'IM_CHAT_' . $chatId,
				'sub_tag' => 'IM_MESS',
				'app_id' => 'Bitrix24',
				'message' => $pushText,
				'send_immediately' => 'Y',
				'advanced_params' => [
					'group' => 'im_message',
					'avatarUrl' => $avatarUrl,
					'senderName' => $chatTitle,
					'senderMessage' => $pushText,
					'filterCallback' => [Signaling::class, 'filterPushesForApple'],
				],
				'params' => [
					'TAG' => 'IM_CHAT_' . $chatId,
					'CHAT_TYPE' => $chatTypeLetter,
					'CATEGORY' => 'ANSWER',
					'URL' => SITE_DIR . 'mobile/ajax.php?mobile_action=im_answer',
					'PARAMS' => [
						'RECIPIENT_ID' => 'chat' . $chatId,
						'MESSAGE_ID' => $messageId,
					],
				],
			],
		];
	}

	public function toArray($initiatorId = 0)
	{
		if($initiatorId == 0)
		{
			$initiatorId = $this->initiatorId;
		}

		return [
			'type' => $this->getEntityType(),
			'id' => (string)$this->getEntityId($initiatorId), //todo: Cast to string for compatibility with immobile. Remove it in a while
			'chatId' => $this->chatId,
			'name' => $this->getName($initiatorId),
			'avatar' => $this->getAvatar($initiatorId),
			'avatarColor' => $this->getAvatarColor($initiatorId),
			'userCounter' => count($this->chatUsers),
			// Client device-scoped-decline gates read this single server-computed flag
			// from the canonical Call::isDeviceScopedRinging() predicate, so client and
			// server never drift (clients must not recombine the raw signals themselves).
			'isDeviceScoped' => (bool)$this->getCall()?->isDeviceScopedRinging(),
			'ownerId' => $this->getOwnerId(),
			'managerIds' => array_values($this->getManagerIds()),
			'advanced' => [
				'chatType' => $this->chatFields['type'],
				'entityType' => $this->chatFields['entity_type'],
				'entityId' => $this->chatFields['entity_id'],
				'entityData1' => $this->chatFields['entity_data_1'],
				'entityData2' => $this->chatFields['entity_data_2'],
				'entityData3' => $this->chatFields['entity_data_3']
			]
		];
	}

	public function canExtendChat(): bool
	{
		if (!$this->chatFields)
		{
			return false;
		}
		if ($this->chatFields['message_type'] === IM_MESSAGE_PRIVATE)
		{
			return true;
		}
		$entityType = $this->chatFields['entity_type'];
		$options = \CIMChat::GetChatOptions();
		return (bool)($options[$entityType]['EXTEND'] ?? true);
	}

	public function getCallDuration(): string
	{
		$startTime = $this->call->getStartDate();
		$endTime = $this->call->getEndDate();
		if (
			!($startTime instanceof \Bitrix\Main\Type\DateTime)
			|| !($endTime instanceof \Bitrix\Main\Type\DateTime)
		)
		{
			return '';
		}

		$interval = $startTime->getDiff($endTime);

		[$hours, $minutes, $seconds] = explode(' ', $interval->format('%H %I %S'));
		$result = [];

		if ((int)$hours > 0)
		{
			$result[] = Loc::getMessage("IM_CALL_INTEGRATION_CHAT_CALL_DURATION_HOURS", [
				"#HOURS#" => (int)$hours
			]);
		}

		if ((int)$minutes > 0)
		{
			$result[] = Loc::getMessage("IM_CALL_INTEGRATION_CHAT_CALL_DURATION_MINUTES", [
				"#MINUTES#" => (int)$minutes
			]);
		}

		if ((int)$seconds > 0 && !((int)$hours > 0))
		{
			$result[] = Loc::getMessage("IM_CALL_INTEGRATION_CHAT_CALL_DURATION_SECONDS", [
				"#SECONDS#" => (int)$seconds
			]);
		}

		return implode(" ", $result);
	}

	private function getChatFields(\Bitrix\Im\V2\Chat $chat): array
	{
		return $chat->toPullFormat();
	}
}