<?php

namespace Bitrix\Call;

use Bitrix\Main\Application;
use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\ORM\EventResult;
use Bitrix\Main\ORM\EntityError;
use Bitrix\Im\V2\Chat;
use Bitrix\Call\Model\CallUserTable;
use Bitrix\Call\Model\CallUserLogTable;
use Bitrix\Call\Service\CallLogService;
use Bitrix\Call\Integration\EntityType;

/**
 * @internal
 */
class EventHandler
{
	/**
	 * @event 'im:OnChatUserDelete'
	 * @see \Bitrix\Im\V2\Chat::sendEventUserDelete
	 * @param Event $event
	 * @return EventResult
	 */
	public static function onChatUserLeave(Event $event): EventResult
	{
		$result = new EventResult();

		/** @var array{chatId: int, userIds: int[]} $eventData */
		$eventData = $event->getParameters();
		if (!empty($eventData['chatId']) && !empty($eventData['userIds']))
		{
			['chatId' => $chatId, 'userIds' => $userIds] = $eventData;

			$type = Call::TYPE_INSTANT;
			$chat = Chat::getInstance($chatId);
			if ($chat->getEntityType() == \Bitrix\Im\V2\Chat\ExtendedType::Videoconference->value)
			{
				$type = Call::TYPE_PERMANENT;
			}

			$call = CallFactory::searchActive(
				type: $type,
				provider: Call::PROVIDER_BITRIX,
				entityType: EntityType::CHAT,
				entityId: 'chat'.$chatId
			);
			if ($call)
			{
				foreach ($userIds as $userId)
				{
					$call->removeUser($userId);
					$call->getSignaling()->sendHangup($userId, $call->getUsers(), null);
				}
				if (Settings::isNewCallsEnabled())
				{
					$chat->getCallToken()->update();
					$call->getSignaling()->sendPushTokenUpdate($chat->getCallToken()->getToken(), $call->getUsers());
				}

				$ids = array_unique(array_merge($userIds, array_keys($call->getCallUsers())));
				foreach ($ids as $userId)
				{
					Recent::updateUserActiveCallsCache($userId);
				}
			}
		}

		return $result;
	}

	/**
	 * @event 'main:OnAfterSetOption_~controller_group_name'
	 * @param Event $event
	 * @return EventResult
	 */
	public static function onControllerGroupNameChange(Event $event): EventResult
	{
		$result = new EventResult(EventResult::SUCCESS);

		if (!\Bitrix\Main\ModuleManager::isModuleInstalled('bitrix24'))
		{
			return $result;
		}

		$eventData = $event->getParameters();
		if (!empty($eventData['value']))
		{
			\Bitrix\Main\Loader::includeModule('bitrix24');
			$newRegion = '';
			if (preg_match("/^([a-z]+)_/is", $eventData['value'], $matches))
			{
				$newRegion = mb_strtolower($matches[1]);
			}
			$currentRegion = \CBitrix24::getPortalZone(\CBitrix24::LICENSE_TYPE_CURRENT) ?: '';
			if ($newRegion !== $currentRegion)
			{
				/** @see \Bitrix\Call\JwtCall::registerPortalAgent */
				$existingAgents = \CAgent::getList([], [
					'MODULE_ID' => 'call',
					'NAME' => '%Bitrix\Call\JwtCall::registerPortalAgent%',
				]);
				if (!$existingAgents->fetch())
				{
					/* @see \Bitrix\Call\JwtCall::registerPortalAgent */
					\CAgent::AddAgent(
						'Bitrix\Call\JwtCall::registerPortalAgent();',
						'call',
						'N',
						60,
						'',
						'Y',
						\ConvertTimeStamp(time()+\CTimeZone::GetOffset() + 10, 'FULL')
					);
				}
			}
		}

		return $result;
	}

	/**
	 * Handles call finished event: update active calls cache for all participants
	 *
	 * @event 'call:onCallFinished'
	 * @param Event $event
	 * @return void
	 */
	public static function onCallFinished(Event $event): void
	{
		$call = $event->getParameter('call');
		if (!$call instanceof Call)
		{
			return;
		}

		Recent::updateCallCache($call->getId());
		Recent::terminateAllCallsInChat($call->getChatId(), $call->getId());

		$userIds = array_keys($call->getCallUsers());
		if ($userIds !== [])
		{
			Application::getInstance()->addBackgroundJob(
				[self::class, 'broadcastCallLogFinish'],
				[$call->getId(), $userIds],
				Application::JOB_PRIORITY_LOW
			);
		}
	}

	/**
	 * Push a final CallLog update to every call participant so clients refresh
	 * record data (duration, status time) right after the call is finished.
	 * Status is reused from the existing b_call_userlog row, matching what
	 * CallLog.list would return on a fresh fetch.
	 *
	 * Runs as a background job via Application::addBackgroundJob — must stay
	 * public static and accept primitives only (no Call instance).
	 */
	public static function broadcastCallLogFinish(int $callId, array $userIds): void
	{
		$existing = CallUserLogTable::getList([
			'select' => ['USER_ID', 'STATUS'],
			'filter' => [
				'=SOURCE_TYPE' => CallUserLogTable::SOURCE_TYPE_CALL,
				'=SOURCE_CALL_ID' => $callId,
				'@USER_ID' => $userIds,
			],
		])->fetchAll();

		if ($existing === [])
		{
			return;
		}

		$service = new CallLogService();
		foreach ($existing as $row)
		{
			$service->addOrUpdateEvent(
				CallUserLogTable::SOURCE_TYPE_CALL,
				$callId,
				(int)$row['USER_ID'],
				$row['STATUS']
			);
		}
	}

	/**
	 * @event 'im:OnChatUserAdd'
	 * @see \Bitrix\Im\V2\Chat::sendEventUsersAdd
	 * @param Event $event
	 * @return EventResult
	 */
	public static function onChatUserAdd(Event $event): EventResult
	{
		$result = new EventResult();

		/** @var array{CHAT_ID: int, NEW_USERS: int[], CHAT: \Bitrix\Im\V2\Chat, ENTITY_TYPE: string, ENTITY_ID: string} $eventData */
		$eventData = $event->getParameters();

		if (!empty($eventData['CHAT_ID']) && !empty($eventData['NEW_USERS']))
		{
			$chatId = (int)$eventData['CHAT_ID'];
			$newUsers = $eventData['NEW_USERS'];
			$chat = $eventData['CHAT'] ?? null;

			// Determine call type based on chat entity type
			$type = Call::TYPE_INSTANT;
			if ($chat && $chat->getEntityType() == \Bitrix\Im\V2\Chat\ExtendedType::Videoconference->value)
			{
				$type = Call::TYPE_PERMANENT;
			}

			// Search for active call in this chat
			$call = CallFactory::searchActive(
				type: $type,
				provider: Call::PROVIDER_BITRIX,
				entityType: EntityType::CHAT,
				entityId: 'chat'.$chatId
			);

			// If active call exists, invite new users to it
			if ($call && $call->getState() !== Call::STATE_FINISHED && $call->hasActiveUsers())
			{
				self::inviteUsersToCall($call, $newUsers);
			}
		}

		return $result;
	}

	/**
	 * Invite users to call using Controller logic
	 * @param Call $call
	 * @param array $userIds
	 */
	private static function inviteUsersToCall(Call $call, array $userIds): void
	{
		$usersToInvite = [];

		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;

			if (!$call->checkAccess($userId))
			{
				continue;
			}

			if (!$call->hasUser($userId))
			{
				if ($call->addUser($userId))
				{
					$usersToInvite[] = $userId;
				}
			}
		}

		if (!empty($usersToInvite))
		{
			$signaling = $call->getSignaling();
			$initiatorId = $call->getInitiatorId();

			foreach ($usersToInvite as $userId)
			{
				if ($call->getScheme() === Call::SCHEME_JWT)
				{
					$signaling->sendCallInviteToUser(
						senderId: $initiatorId,
						toUserId: $userId,
						isLegacyMobile: false,
						video: true,
						sendPush: true,
					);
				}
				else
				{
					$signaling->sendInviteToUser(
						senderId: $initiatorId,
						toUserId: $userId,
						invitedUsers: $usersToInvite,
						isLegacyMobile: false,
						video: true,
						sendPush: true
					);
				}
				Recent::updateUserActiveCallsCache($userId);
			}

			if (count($usersToInvite) > 0)
			{
				$signaling->sendUsersInvited($initiatorId, $usersToInvite, $call->getUsers(), true);
			}

			Recent::updateCallCache($call->getId());
		}
	}

	/**
	 * Handles call user state change events for logging
	 *
	 * @event 'im:OnCallUserStateChange'
	 * @see \Bitrix\Call\CallUser::updateState
	 * @param Event $event
	 * @return EventResult
	 */
	public static function onCallUserStateChange(Event $event): EventResult
	{
		$result = new EventResult();

		$eventData = $event->getParameters();
		$callId = $eventData['callId'] ?? null;
		$userId = $eventData['userId'] ?? null;
		$oldState = $eventData['oldState'] ?? null;
		$newState = $eventData['newState'] ?? null;

		if (!$callId || !$userId || !$newState)
		{
			return $result;
		}

		$statusMap = [
			'unavailable:ready' => 'initiated',
			'declined:ready' => 'answered',
			'calling:ready' => 'answered',
			':declined' => 'declined',
			':busy' => 'missed',
			'unavailable:unavailable' => 'missed',
			'unavailable:idle' => 'missed',
			'calling:idle' => 'missed',
		];

		$transitionKey = $oldState . ':' . $newState;
		$status = $statusMap[$transitionKey] ?? null;
		if (!$status)
		{
			$status = $statusMap[':' . $newState] ?? null;
		}
		if (!$status)
		{
			return $result;
		}

		$service = new CallLogService();
		$service->addOrUpdateEvent('call', $callId, $userId, $status);

		return $result;
	}

	/**
	 * Handles event when portal domain changes its domain.
	 * @param array{new_domain: string, old_domain: string} $domains
	 * @return EventResult
	 */
	public static function onPortalDomainChange(array $domains): EventResult
	{
		$result = new EventResult();

		/*
		$publicUrl = $domains['new_domain'];
		if (!str_starts_with($publicUrl, 'https://') && !str_starts_with($publicUrl, 'http://'))
		{
			$publicUrl = 'https://' . $publicUrl;
		}
		*/

		if (Settings::isNewCallsEnabled())
		{
			/** @see JwtCall::checkPortalRegistrationAgent() */
			\CAgent::AddAgent(
				'Bitrix\Call\JwtCall::checkPortalRegistrationAgent();',
				'call',
				'N',
				300,
				'',
				'Y',
				\ConvertTimeStamp(time()+\CTimeZone::GetOffset() + rand(5, 20), 'FULL')
			);
		}

		return $result;
	}

	/**
	 * Handles im changes roles.
	 *
	 * @event 'im:OnChangeUserRoles'
	 * @param Event $event
	 * @return EventResult
	 */
	public static function onChangeUserRoles(Event $event): EventResult
	{
		$result = new EventResult();

		$eventData = $event->getParameters();

		if (
			empty($eventData['userIds'])
			|| empty($eventData['chatId'])
			|| empty($eventData['role'])
		)
		{
			return $result;
		}

		if (!in_array(
			$eventData['role'],
			[
				Chat::ROLE_OWNER,
				Chat::ROLE_MANAGER,
				Chat::ROLE_MEMBER,
				Chat::ROLE_GUEST,
				Chat::ROLE_NONE,
			],
			true
		))
		{
			return $result;
		}

		$call = CallFactory::searchActive(
			type: Call::TYPE_INSTANT,
			provider: Call::PROVIDER_BITRIX,
			entityType: EntityType::CHAT,
			entityId: 'chat' . $eventData['chatId']
		);
		if (!$call)
		{
			return $result;
		}

		$balancerClient = new BalancerClient();
		$changeResult = $balancerClient->changeUserRole($call, $eventData['userIds'], $eventData['role']);
		if (!$changeResult->isSuccess())
		{
			foreach ($changeResult->getErrors() as $error)
			{
				$result->addError(new EntityError($error->getMessage(), $error->getCode()));
			}
 		}

		return $result;
	}

	/**
	 * Background-job entry point for {@see Call::markUsersCalling()}.
	 *
	 * Replays the per-user OnCallUserStateChange events that used to fire
	 * synchronously inside markUsersCalling. Listeners (e.g.
	 * CallLogService::addOrUpdateEvent) run after the HTTP response is
	 * flushed, so the response time is no longer blocked by N × GET_LOCK +
	 * 4–6 SQL per invitee.
	 *
	 * Compare-and-set guard: between the schedule call and this background
	 * job a peer can transition further (calling → ready/idle/declined via
	 * answerAction/declineAction/hangup). The synchronous handlers for those
	 * terminal transitions write `b_call_user_log` immediately. Re-read the
	 * current STATE per (callId, userId) and drop events whose recorded
	 * `newState` no longer matches — otherwise a stale background event
	 * could feed `CallLogService::addOrUpdateEvent` (unconditional addMerge
	 * by SOURCE_CALL_ID/USER_ID) and overwrite the terminal call_user_log
	 * record. The current $statusMap in {@see self::onCallUserStateChange()}
	 * does not map *:calling transitions, so the issue is latent today —
	 * but the guard makes deferred dispatch safe against any future
	 * extension of the map or new deferred transitions.
	 *
	 * @see EventHandler::onCallUserStateChange
	 *
	 * @param array<int, array{callId:int,userId:int,oldState:string,newState:string}> $changes
	 * @internal
	 */
	public static function dispatchUserStateChangeEventsBatch(array $changes): void
	{
		if ($changes === [])
		{
			return;
		}

		$affected = [];
		$allUserIds = [];
		foreach ($changes as $change)
		{
			$callId = (int)($change['callId'] ?? 0);
			$userId = (int)($change['userId'] ?? 0);
			if ($callId > 0 && $userId > 0)
			{
				$affected[$callId][$userId] = true;
				$allUserIds[$userId] = true;
			}
		}
		if ($affected === [])
		{
			return;
		}

		$rows = CallUserTable::getList([
			'select' => ['CALL_ID', 'USER_ID', 'STATE'],
			'filter' => [
				'=CALL_ID' => array_keys($affected),
				'=USER_ID' => array_keys($allUserIds),
			],
		])->fetchAll();

		$currentStates = [];
		foreach ($rows as $row)
		{
			$callId = (int)$row['CALL_ID'];
			$userId = (int)$row['USER_ID'];
			if (isset($affected[$callId][$userId]))
			{
				$currentStates[$callId][$userId] = (string)$row['STATE'];
			}
		}

		$eventManager = EventManager::getInstance();
		foreach ($changes as $change)
		{
			$callId = (int)($change['callId'] ?? 0);
			$userId = (int)($change['userId'] ?? 0);
			$newState = (string)($change['newState'] ?? '');

			if (($currentStates[$callId][$userId] ?? null) !== $newState)
			{
				continue;
			}

			try
			{
				$eventManager->send(new Event(
					'im',
					'OnCallUserStateChange',
					[
						'callId' => $callId,
						'userId' => $userId,
						'oldState' => (string)($change['oldState'] ?? ''),
						'newState' => $newState,
					],
				));
			}
			catch (\Throwable $e)
			{}
		}
	}
}
