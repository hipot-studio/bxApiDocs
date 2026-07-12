<?php

namespace Bitrix\Call\Controller;

use Bitrix\Main\Application;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Call\Call\ConferenceCall;
use Bitrix\Call\Recent;
use Bitrix\Call\Signaling;
use Bitrix\Call\CallUser;
use Bitrix\Call\Integration\EntityType;
use Bitrix\Call\Call\Registry;
use Bitrix\Call\Util;
use Bitrix\Call\CallFactory;
use Bitrix\Call\Error;
use Bitrix\Call\DTO;
use Bitrix\Call\JwtCall;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Controller\Filter\UniqueRequestFilter;
use Bitrix\Im\V2\Chat;

/**
 * @internal
 */
class Call extends JwtController
{
	protected const LOCK_TTL = 10; // in seconds

	public function getAutoWiredParameters(): array
	{
		return array_merge([
			new ExactParameter(
				DTO\CallRequest::class,
				'callRequest',
				$this->decodeJwtParameter()
			),
			new ExactParameter(
				DTO\CallUserRequest::class,
				'callUserRequest',
				$this->decodeJwtParameter()
			),
			new ExactParameter(
				DTO\CallTokenRequest::class,
				'tokenRequest',
				function ($className, $params = [])
				{
					$parameters = $this->getSourceParametersList()[0];
					$chatData = new DTO\CallTokenRequest($parameters);
					return $chatData;
				}
			),
			new ExactParameter(
				DTO\UserRequest::class,
				'userRequest',
				$this->decodeJsonParameter()
			),
			new ExactParameter(
				DTO\CallPushRequest::class,
				'pushRequest',
				$this->decodeJwtParameter()
			),
		], parent::getAutoWiredParameters());
	}

	public function configureActions()
	{
		return [
			'startCall' => [
				'+prefilters' => [
					new UniqueRequestFilter(),
				],
			],
			'finishCall' => [
				'+prefilters' => [
					new UniqueRequestFilter(),
				],
			],
			'createChildCall' => [
				'+prefilters' => [
					new UniqueRequestFilter(),
				],
			],
			'userStatus' => [
				'+prefilters' => [
					new UniqueRequestFilter(),
				],
			],
			'startPush' => [
				'+prefilters' => [
					new UniqueRequestFilter()
				]
			],
		];
	}

	/**
	 * Return call token
	 *
	 * @restMethod call.Call.getCallToken
	 *
	 * @param DTO\CallTokenRequest $tokenRequest
	 * @return array
	 */
	public function getCallTokenAction(DTO\CallTokenRequest $tokenRequest): array
	{
		$currentUserId = (int)$this->getCurrentUser()->getId();

		$callToken = '';
		if ($tokenRequest->chatId)
		{
			Loader::includeModule('im');

			$chat = Chat::getInstance((int)$tokenRequest->chatId);
			if (!$chat->getId() || !$chat->checkAccess($currentUserId)->isSuccess())
			{
				$this->addError(new Error('access_denied', 'Access to chat denied'));
				return [];
			}

			$callToken = JwtCall::getCallToken($tokenRequest->chatId, $tokenRequest->additionalData);
		}

		return [
			'callToken' => $callToken,
			'userToken' => JwtCall::getUserJwt($currentUserId),
		];
	}

	/**
	 * @restMethod call.Call.startCall
	 *
	 * @param DTO\CallRequest $callRequest
	 * @return array|null
	 */
	public function startCallAction(DTO\CallRequest $callRequest): ?array
	{
		Loader::includeModule('im');

		// Validate required parameters
		if (!$callRequest->chatId)
		{
			$this->addError(new Error('missing_chat_id', 'Chat ID is required'));
			return [
				'result' => false,
				'errorCode' => 'missing_chat_id',
				'errorMessage' => 'Chat ID is required',
			];
		}

		if (!$callRequest->initiatorUserId)
		{
			$this->addError(new Error('missing_initiator_user_id', 'Initiator user ID is required'));
			return [
				'result' => false,
				'errorCode' => 'missing_initiator_user_id',
				'errorMessage' => 'Initiator user ID is required',
			];
		}

		if (!$callRequest->provider)
		{
			$this->addError(new Error('missing_provider', 'Provider is required'));
			return [
				'result' => false,
				'errorCode' => 'missing_provider',
				'errorMessage' => 'Provider is required',
			];
		}

		if (!$callRequest->callUuid && !$callRequest->roomId)
		{
			$this->addError(new Error('missing_call_identifier', 'Call UUID or Room ID is required'));
			return [
				'result' => false,
				'errorCode' => 'missing_call_identifier',
				'errorMessage' => 'Call UUID or Room ID is required',
			];
		}

		try
		{
			$tokenVersion = JwtCall::getTokenVersion($callRequest->chatId);
			if ($tokenVersion > $callRequest->tokenVersion)
			{
				$this->addError(new Error('call_token_version_deprecated', 'Call token version deprecated'));
				return [
					'result' => false,
					'errorCode' => 'call_token_version_deprecated',
					'errorMessage' => 'Call token version deprecated',
				];
			}

			$userId = $callRequest->initiatorUserId;
			$roomId = $callRequest->roomId ?: $callRequest->callUuid;
			$entityId = \Bitrix\Im\Dialog::getDialogId($callRequest->chatId, $userId);

			$lockName = static::getLockNameWithCallId('call_state', $roomId);
			if (!Application::getConnection()->lock($lockName, static::LOCK_TTL))
			{
				$this->addError(new Error('could_not_lock', 'Could not get exclusive lock'));
				return null;
			}

			$call = null;
			$availableUsers = [];

			try
			{
				if ($callRequest->provider == \Bitrix\Call\Call::PROVIDER_PLAIN)
				{
					if (CallFactory::hasUserActiveCalls((int)$entityId, $userId))
					{
						$targetUserId = (int)$entityId;
						$chat = \Bitrix\Im\V2\Chat\ChatFactory::getInstance()->getPrivateChat($userId, $targetUserId);
						if ($chat->getId() > 0)
						{
							$notifyService = \Bitrix\Call\NotifyService::getInstance();
							$notifyService->sendOpponentBusyMessage($userId, $targetUserId);
						}

						$callFields = [
							'TYPE' => $callRequest->callType,
							'PROVIDER' => $callRequest->provider,
							'ENTITY_TYPE' => EntityType::CHAT,
							'ENTITY_ID' => $chat->getId(),
							'INITIATOR_ID' => $userId,
							'UUID' => $roomId,
							'SCHEME' => \Bitrix\Call\Call::SCHEME_JWT,
							'STATE' => \Bitrix\Call\Call::STATE_FINISHED,
						];
						$callObject = CallFactory::getCallInstance($callRequest->provider, $callFields);

						$callObject->save();

						$participants = [
							['id' => $userId, 'state' => CallUser::STATE_READY],
							['id' => $targetUserId, 'state' => CallUser::STATE_BUSY],
						];

						foreach ($participants as $user)
						{
							CallUser::create([
								'CALL_ID' => $callObject->getId(),
								'USER_ID' => $user['id'],
								'STATE' => $user['state'],
								'LAST_SEEN' => null
							])->save();
						}

						$callObject->getSignaling()->sendFinishToInitiator($userId);

						return [
							'result' => false,
							'errorCode' => 'user_is_busy',
							'errorMessage' => 'User is currently busy on another call',
						];
					}
				}

				// Terminate any stuck 1-1 calls of the initiator before starting a new one.
				// For 1-1 (PROVIDER_PLAIN) a user can only be in one call at a time, so
				// chat-scoped cleanup is not enough — a stuck call with a different opponent
				// would otherwise remain in recent and block subsequent calls on the frontend.
				if ($callRequest->provider === \Bitrix\Call\Call::PROVIDER_PLAIN)
				{
					Recent::terminateStuckPlainCallsForUser($userId);
				}
				else
				{
					Recent::terminateAllCallsInChat($callRequest->chatId, null);
				}

				$prevCall = CallFactory::searchActiveCall(
					type: $callRequest->callType,
					provider: $callRequest->provider,
					entityType: EntityType::CHAT,
					entityId: $entityId,
				);
				if ($prevCall instanceof \Bitrix\Call\Call)
				{
					if ($prevCall->isAiAnalyzeEnabled())
					{
						$prevCall
							->disableAudioRecord()
							->disableAiAnalyze()
							->save()
						;
					}
					$prevCall->finish();
				}

				$call = CallFactory::createWithEntity(
					type: $callRequest->callType,
					provider: $callRequest->provider,
					entityType: EntityType::CHAT,
					entityId: $entityId,
					initiatorId: $userId,
					callUuid: $roomId,
					scheme: \Bitrix\Call\Call::SCHEME_JWT,
				);

				if ($call->hasErrors())
				{
					$this->addErrors($call->getErrors());
					$call = null;
					return null;
				}

				$this->setUserStateReady($call, $userId, $callRequest->legacyMobile);

				$users = array_values(array_diff($call->getUsers(), [$userId]));

				$usersInOtherCalls = CallFactory::filterUsersInActiveCalls($users, $call->getId());
				$usersBusy = array_flip($usersInOtherCalls);
				$availableUsers = array_values(array_filter(
					$users,
					static fn ($invitedUserId): bool => !isset($usersBusy[(int)$invitedUserId]),
				));

				if (
					$call->getType() != $call::TYPE_PERMANENT
					&& empty($availableUsers)
				)
				{
					$call->finish();
					$call = null;

					return [
						'result' => false,
						'errorCode' => 'all_users_busy',
						'errorMessage' => 'All users are currently in other calls',
					];
				}

				// Publish STATE_INVITING while still holding the lock so that a
				// concurrent answerAction (which re-reads state under the same
				// lock) never observes STATE_NEW once startCall has committed.
				if ($call->getState() === \Bitrix\Call\Call::STATE_NEW)
				{
					$call->updateState(\Bitrix\Call\Call::STATE_INVITING);
				}
			}
			finally
			{
				Application::getConnection()->unlock($lockName);
			}

			// Post-lock phase: pull broadcast, push, cache warm-up, addUser loop.
			// These take tens of seconds for N≥100 participants but must not
			// block answer/finish of peers who already see Call::incoming.
			$call->getSignaling()->sendLogToken($userId);

			// Initiator's active-calls cache is rebuilt together with the invited
			// set inside dispatchInviteBroadcasts — one background job instead of two.
			$this->inviteUsers(
				call: $call,
				userIds: $availableUsers,
				isVideo: $callRequest->video,
				isLegacyMobile: false,
				isShow: true,
				isRepeated: false,
				sendMode: Signaling::MODE_WEB,
				usersInOtherCalls: $usersInOtherCalls,
				extraCacheUsers: [$userId],
				earlyFlush: true,
			);

			$aiAvailability = CallAISettings::checkAIAvailabilityInCall();

			return [
				'callId' => $call->getId(),
				'tokenVersion' => $tokenVersion,
				'autoStartAIRecording' => $call->autoStartRecording(),
				'AIAvailableInCall' => $aiAvailability->isSuccess(),
				'AIErrorCode' => $aiAvailability->getError()?->getCode(),
				'AIErrorMessage' => $aiAvailability->getError()?->getMessage(),
			];
		}
		catch (\Throwable $e)
		{
			$this->addError(new Error($e->getCode(), $e->getMessage()));
			return [
				'result' => false,
				'errorCode' => $e->getCode(),
				'errorMessage' => $e->getMessage(),
			];
		}
	}

	/**
	 * @restMethod call.Call.startPush
	 *
	 * @param DTO\CallPushRequest $pushRequest
	 * @return array|null
	 */
	public function startPushAction(DTO\CallPushRequest $pushRequest): ?array
	{
		try
		{
			Loader::includeModule('im');

			$callUuid = $pushRequest->roomId ?: $pushRequest->callUuid;
			$call = Registry::getCallWithUuid($callUuid);

			if (!$call)
			{
				$this->addError(new Error('call_not_found', 'Call not found'));
				return [
					'result' => false,
					'errorCode' => 'call_not_found',
					'errorMessage' => 'Call not found',
				];
			}

			if ($call->getState() === \Bitrix\Call\Call::STATE_FINISHED)
			{
				$this->addError(new Error('call_finished', 'Call already finished'));
				return [
					'result' => false,
					'errorCode' => 'call_finished',
					'errorMessage' => 'Call already finished',
				];
			}

			if (!$call->hasActiveUsers(false))
			{
				$this->addError(new Error('call_inactive', 'Call has no active users'));
				return [
					'result' => false,
					'errorCode' => 'call_inactive',
					'errorMessage' => 'Call has no active users',
				];
			}

			$excluded = array_merge(
				array_map('intval', $pushRequest->usersIds),
				[$pushRequest->initiatorUserId]
			);

			$allUsers = array_map('intval', $call->getUsers());
			$userIds = array_diff($allUsers, $excluded);

			$userIds = array_filter($userIds, function ($userId) use ($call)
			{
				if (!$call->checkAccess($userId))
				{
					return false;
				}

				$callUser = $call->getUser($userId);
				if (!$callUser)
				{
					return true;
				}

				$state = $callUser->getState();

				return in_array($state, [
					CallUser::STATE_IDLE,
					CallUser::STATE_CALLING,
					CallUser::STATE_UNAVAILABLE,
				], true);
			});

			if (!empty($userIds))
			{
				$call->sendInviteUsers(
					senderId: $pushRequest->initiatorUserId,
					toUserIds: $userIds,
					isLegacyMobile: ($pushRequest->legacyMobile == 'Y'),
					video: ($pushRequest->video == 'Y'),
					sendPush: true,
					sendMode: Signaling::MODE_MOBILE
				);
			}

			return ['result' => true];
		}
		catch (\Throwable $e)
		{
			$this->addError(new Error($e->getCode(), $e->getMessage()));
			return [
				'result' => false,
				'errorCode' => $e->getCode(),
				'errorMessage' => $e->getMessage(),
			];
		}
	}

	/**
	 * @restMethod call.Call.finishCall
	 *
	 * @param DTO\CallRequest $callRequest
	 * @return array|null
	 */
	public function finishCallAction(DTO\CallRequest $callRequest): ?array
	{
		Loader::includeModule('im');

		// Validate required parameters
		if (!$callRequest->callUuid && !$callRequest->roomId)
		{
			$this->addError(new Error('missing_call_identifier', 'Call UUID or Room ID is required'));
			return [
				'result' => false,
				'errorCode' => 'missing_call_identifier',
				'errorMessage' => 'Call UUID or Room ID is required',
			];
		}

		$callUuid = $callRequest->roomId ?: $callRequest->callUuid;

		// Lock to prevent race conditions with startCall
		$lockName = static::getLockNameWithCallId('call_state', $callUuid);
		if (!Application::getConnection()->lock($lockName, static::LOCK_TTL))
		{
			$this->addError(new Error('could_not_lock', 'Could not get exclusive lock'));
			return [
				'result' => false,
				'errorCode' => 'could_not_lock',
				'errorMessage' => 'Could not get exclusive lock',
			];
		}

		try
		{
			$call = Registry::getCallWithUuid($callUuid);
			if (!$call)
			{
				$this->addError(new Error('call_not_found', 'Call not found'));
				return [
					'result' => false,
					'errorCode' => 'call_not_found',
					'errorMessage' => 'Call not found',
				];
			}

			$userId = $callRequest->userId ?: $call->getInitiatorId();
			$call->setActionUserId($userId);

			if ($call->isAudioRecordEnabled())
			{
				$call->disableAudioRecord();
			}

			$call->save();
			$call->finish();

			// Terminate all other active calls in the same chat after this call finishes
			Recent::terminateAllCallsInChat($call->getChatId(), $call->getId());

			Recent::scheduleUpdateCallCache($call->getId());
		}
		finally
		{
			Application::getConnection()->unlock($lockName);
		}

		return [
			'call' => $call->toArray($userId),
			'connectionData' => $call->getConnectionData($userId),
			'logToken' => $call->getLogToken($userId)
		];
	}

	protected function inviteUsers(
		\Bitrix\Call\Call $call,
		array $userIds,
		bool $isVideo = false,
		bool $isLegacyMobile = false,
		bool $isShow = true,
		bool $isRepeated = false,
		string $sendMode = Signaling::MODE_ALL,
		?array $usersInOtherCalls = null,
		array $extraCacheUsers = [],
		bool $earlyFlush = false
	): void
	{
		$payload = $this->prepareInviteUsers($call, $userIds, $isRepeated);
		$this->dispatchInviteBroadcasts(
			call: $call,
			payload: $payload,
			isVideo: $isVideo,
			isLegacyMobile: $isLegacyMobile,
			isShow: $isShow,
			isRepeated: $isRepeated,
			sendMode: $sendMode,
			usersInOtherCalls: $usersInOtherCalls,
			extraCacheUsers: $extraCacheUsers,
			earlyFlush: $earlyFlush,
		);
	}

	/**
	 * @return array{userIds: int[], usersToInvite: int[], existingUsers: int[]}
	 */
	protected function prepareInviteUsers(
		\Bitrix\Call\Call $call,
		array $userIds,
		bool $isRepeated,
	): array
	{
		if (\Bitrix\Im\User::getInstance()->isExtranet())
		{
			$userIds = \Bitrix\Im\Integration\Socialnetwork\Extranet::filterUserList($userIds, \Bitrix\Im\User::getInstance()->getId()) ?: [];
		}
		$userIds = array_values(array_unique(array_map('intval', $userIds)));

		$existingUsers = [];
		if ($isRepeated === false && $call->getAssociatedEntity())
		{
			foreach ($userIds as $userId)
			{
				if ($call->hasUser($userId))
				{
					$existingUsers[] = $userId;
				}
			}
		}

		$call->addUsers($userIds);

		$usersToInvite = [];
		foreach ($userIds as $userId)
		{
			if ($call->hasUser($userId))
			{
				$usersToInvite[] = $userId;
			}
		}

		// markUsersCalling stays out of prepare: it fans out N synchronous
		// OnCallUserStateChange listeners (each takes a GET_LOCK and runs
		// 4–6 SQL inside CallLogService::addOrUpdateEvent), so it is moved
		// after the Call::incoming broadcast in dispatchInviteBroadcasts to
		// shorten the time-to-first-pull.
		//$call->markUsersCalling($usersToInvite);

		if ($call->getState() === \Bitrix\Call\Call::STATE_NEW)
		{
			$call->updateState(\Bitrix\Call\Call::STATE_INVITING);
		}

		return [
			'userIds' => $userIds,
			'usersToInvite' => $usersToInvite,
			'existingUsers' => $existingUsers,
		];
	}

	/**
	 * @param array{userIds: int[], usersToInvite: int[], existingUsers: int[]} $payload
	 */
	protected function dispatchInviteBroadcasts(
		\Bitrix\Call\Call $call,
		array $payload,
		bool $isVideo,
		bool $isLegacyMobile,
		bool $isShow,
		bool $isRepeated,
		string $sendMode = Signaling::MODE_ALL,
		?array $usersInOtherCalls = null,
		array $extraCacheUsers = [],
		bool $earlyFlush = false
	): void
	{
		$userIds = $payload['userIds'] ?? [];
		$usersToInvite = $payload['usersToInvite'] ?? [];
		$existingUsers = $payload['existingUsers'] ?? [];

		$cacheUsers = $extraCacheUsers === [] ? $usersToInvite : array_merge($extraCacheUsers, $usersToInvite);
		$cacheUsers = array_values(array_unique($cacheUsers));

		Recent::scheduleUpdateUsersActiveCallsCache($cacheUsers);

		if (!empty($existingUsers))
		{
			$call->getAssociatedEntity()?->onExistingUsersInvite($existingUsers);
		}

		if (count($usersToInvite) === 0 && !($call instanceof ConferenceCall))
		{
			$this->addError(new Error("empty_users", "No users to invite"));
			return;
		}

		if (count($usersToInvite) !== 0)
		{
			// Order matters here:
			//   1. sendInviteUsers — queue Call::incoming + per-user push.
			//   2. markUsersCalling — flip invitees UNAVAILABLE/IDLE → CALLING
			//      BEFORE the wire broadcast leaves so that a fast answerAction
			//      sees STATE_CALLING and emits the canonical
			//      `calling → ready` (= "answered") transition. Heavy listeners
			//      have been deferred (Etap-2 → background job), so this step
			//      is now a single bulk UPDATE + re-SELECT, ~20 ms — safe to
			//      run synchronously before flush.
			//   3. Pull\Event::send() (only when $earlyFlush) — synchronous
			//      flush to push-go. Opt-in: startCallAction passes true to
			//      cut time-to-first-pull on the start path. inviteAction
			//      etc. keep the default OnAfterEpilog flush — otherwise N
			//      synchronous b_pull_push_queue INSERTs from
			//      executePushEvents() land inside the response.
			//   4. sendUsersInvited — peer-side notification, queued and
			//      delivered through the regular OnAfterEpilog flush.
			$sendPush = $isRepeated !== true;
			$call->sendInviteUsers(
				senderId: $this->getCurrentUser()->getId(),
				toUserIds: $usersToInvite,
				isLegacyMobile: $isLegacyMobile,
				video: $isVideo,
				sendPush: $sendPush,
				sendMode: $sendMode,
				usersInOtherCalls: $usersInOtherCalls
			);

			$call->markUsersCalling($usersToInvite);

			if ($earlyFlush && Loader::includeModule('pull'))
			{
				Application::getInstance()->addBackgroundJob(
					[\Bitrix\Pull\Event::class, 'send'],
					[],
					Application::JOB_PRIORITY_NORMAL
				);
			}

			$allUsers = $call->getUsers();
			$otherUsers = array_diff($allUsers, $userIds);
			$call->getSignaling()->sendUsersInvited(
				senderId: $this->getCurrentUser()->getId(),
				toUserIds: $otherUsers,
				users: $usersToInvite,
				show: $isShow
			);
		}
	}

	/**
	 * @restMethod call.Call.answer
	 *
	 * @param DTO\UserRequest $userRequest
	 * @return array|null
	 */
	public function answerAction(DTO\UserRequest $userRequest): ?array
	{
		$isLegacyMobile = $userRequest->legacyMobile === 'Y';
		$callUuid = $userRequest->roomId ?: $userRequest->callUuid;
		$call = Registry::getCallWithUuid($callUuid);
		if (!$call)
		{
			$this->addError(new Error('call_not_found', 'Call not found'));

			return [
				'result' => false,
				'errorCode' => 'call_not_found',
				'errorMessage' => 'Call not found',
			];
		}

		$currentUserId = $this->getCurrentUser()->getId();
		if (!$call->checkAccess($currentUserId))
		{
			return null;
		}

		$lockName = static::getLockNameWithCallId('user'.$currentUserId, $callUuid);
		if (!Application::getConnection()->lock($lockName, static::LOCK_TTL))
		{
			$this->addError(new Error('could_not_lock', 'Could not get exclusive lock'));

			return [
				'result' => false,
				'errorCode' => 'could_not_lock',
				'errorMessage' => 'Could not get exclusive lock',
			];
		}

		$callId = $call->getId();
		$callStateLockName = static::getLockNameWithCallId('call_state', $callUuid);
		$callStateLocked = false;
		try
		{
			// Acquire call_state lock to synchronize with finishCallAction
			if (!Application::getConnection()->lock($callStateLockName, static::LOCK_TTL))
			{
				$this->addError(new Error('could_not_lock', 'Could not get exclusive lock'));

				return [
					'result' => false,
					'errorCode' => 'could_not_lock',
					'errorMessage' => 'Could not get exclusive lock',
				];
			}
			$callStateLocked = true;

			// Re-read under both locks for authoritative state
			Registry::clearCache($callId);
			$call = Registry::getCallWithUuid($callUuid);

			if (!$call || $call->getState() === \Bitrix\Call\Call::STATE_FINISHED)
			{
				$this->addError(new Error('call_finished', 'Call is already finished'));

				return [
					'result' => false,
					'errorCode' => 'call_finished',
					'errorMessage' => 'Call is already finished',
				];
			}

			$this->setUserStateReady($call, $currentUserId, $isLegacyMobile);
			$call->getSignaling()->sendAnswer($currentUserId, $userRequest->callInstanceId, $isLegacyMobile);
		}
		finally
		{
			if ($callStateLocked)
			{
				Application::getConnection()->unlock($callStateLockName);
			}
			Application::getConnection()->unlock($lockName);
		}

		Recent::scheduleUpdateCallCache($call->getId());

		return ['result' => true];
	}

	/**
	 * @restMethod call.Call.decline
	 *
	 * @param DTO\UserRequest $userRequest
	 * @return array|null
	 */
	public function declineAction(DTO\UserRequest $userRequest): ?array
	{
		Loader::includeModule('im');

		$currentUserId = $this->getCurrentUser()->getId();
		$callUuid = $userRequest->roomId ?: $userRequest->callUuid;

		$call = Registry::getCallWithUuid($callUuid);
		if (!$call)
		{
			$this->addError(new Error( "call_not_found", "Call not found"));
			return [
				'result' => false,
				'errorCode' => 'call_not_found',
				'errorMessage' => 'Call not found',
			];
		}

		if (!$call->checkAccess($currentUserId))
		{
			return [
				'result' => false,
				'errorCode' => 'access_denied',
				'errorMessage' => 'You do not have access to the call',
			];
		}

		$callUser = $call->getUser($currentUserId);
		if (!$callUser)
		{
			$this->addError(new Error("unknown_call_user", "User is not part of the call"));
			return [
				'result' => false,
				'errorCode' => 'unknown_call_user',
				'errorMessage' => 'User is not part of the call',
			];
		}

		if ($callUser->getState() === CallUser::STATE_READY)
		{
			$this->addError(new Error("wrong_user_state", "Can not decline in {$callUser->getState()} user state"));
			return [
				'result' => false,
				'errorCode' => 'wrong_user_state',
				'errorMessage' => "Can not decline in {$callUser->getState()} user state",
			];
		}

		$lockName = static::getLockNameWithCallId('user'.$currentUserId, $callUuid);
		if (!Application::getConnection()->lock($lockName, static::LOCK_TTL))
		{
			$this->addError(new Error("could_not_lock", "Could not get exclusive lock"));
			return [
				'result' => false,
				'errorCode' => 'could_not_lock',
				'errorMessage' => 'Could not get exclusive lock',
			];
		}

		// Re-load from DB after lock to get fresh state
		Registry::clearCache($call->getId());
		$call = Registry::getCallWithUuid($callUuid);

		if ($call->getState() === \Bitrix\Call\Call::STATE_FINISHED)
		{
			Application::getConnection()->unlock($lockName);
			return ['result' => true];
		}

		$callUser = $call->getUser($currentUserId);
		if (!$callUser)
		{
			Application::getConnection()->unlock($lockName);
			return ['result' => true];
		}

		if ($callUser->getState() === CallUser::STATE_READY)
		{
			Application::getConnection()->unlock($lockName);
			$this->addError(new Error("wrong_user_state", "Can not decline in {$callUser->getState()} user state"));
			return [
				'result' => false,
				'errorCode' => 'wrong_user_state',
				'errorMessage' => "Can not decline in {$callUser->getState()} user state",
			];
		}

		if ($userRequest->code === 486)
		{
			$callUser->updateState(CallUser::STATE_BUSY);
		}
		else
		{
			$callUser->updateState(CallUser::STATE_DECLINED);
		}

		$callUser->updateLastSeen(new DateTime());
		Application::getConnection()->unlock($lockName);

		// Smart-targeted broadcast: only initiator + sender + active (READY)
		// peers receive Call::hangup. Ringing (STATE_CALLING) peers are
		// excluded — bounds pull fan-out independent of chat size and
		// mitigates the decline-storm pattern from incident #421808.
		$call->getSignaling()->sendHangupForDecline(
			$currentUserId,
			$userRequest->callInstanceId,
			$userRequest->code,
		);

		if (!$call->hasActiveUsers(false))
		{
			$call->setActionUserId($currentUserId)->finish();
		}

		Recent::scheduleUpdateCallCache($call->getId());
		Recent::scheduleUpdateUsersActiveCallsCache([$currentUserId]);

		return ['result' => true];
	}

	/**
	 * @restMethod call.Call.userStatus
	 *
	 * @param DTO\CallUserRequest $callUserRequest
	 * @return array|null
	 */
	public function userStatusAction(DTO\CallUserRequest $callUserRequest): ?array
	{
		$isLegacyMobile = $callUserRequest->legacyMobile === "Y";
		$callUuid = $callUserRequest->roomId ?: $callUserRequest->callUuid;
		if (!$callUuid)
		{
			$this->addError(new Error('missing_call_identifier', 'Call UUID or Room ID is required'));
			return [
				'result' => false,
				'errorCode' => 'missing_call_identifier',
				'errorMessage' => 'Call UUID or Room ID is required',
			];
		}

		$call = Registry::getCallWithUuid($callUuid);
		if (!$call)
		{
			$this->addError(new Error("call_not_found", "Call not found"));
			return [
				'result' => false,
				'errorCode' => 'call_not_found',
				'errorMessage' => 'Call not found',
			];
		}

		if ($call->getState() === \Bitrix\Call\Call::STATE_FINISHED)
		{
			$this->addError(new Error('call_finished', 'Call already finished'));
			return [
				'result' => false,
				'errorCode' => 'call_finished',
				'errorMessage' => 'Call already finished',
			];
		}

		if (!empty($callUserRequest->connectedUsers))
		{
			$mobileByUser = [];
			foreach ($callUserRequest->connectedUsers as $user)
			{
				$uid = (int)($user->userId ?? 0);
				if ($uid <= 0)
				{
					continue;
				}
				$mobileByUser[$uid] = isset($user->isMobile) ? (bool)$user->isMobile : $isLegacyMobile;
			}

			$allowed = array_flip($call->checkAccessBatch(array_keys($mobileByUser)));
			$applicable = array_intersect_key($mobileByUser, $allowed);

			if ($applicable !== [])
			{
				$call->setUsersStateReady($applicable);

				$accessibleSenders = array_values(array_filter(
					$callUserRequest->connectedUsers,
					static fn ($user) => isset($allowed[(int)($user->userId ?? 0)]),
				));
				$call->getSignaling()->sendUsersAnswered($accessibleSenders, $isLegacyMobile);
			}
		}

		if (!empty($callUserRequest->disconnectedUsers))
		{
			$disconnectIds = [];
			foreach ($callUserRequest->disconnectedUsers as $user)
			{
				$uid = (int)($user->userId ?? 0);
				if ($uid > 0)
				{
					$disconnectIds[] = $uid;
				}
			}

			if ($disconnectIds !== [])
			{
				$transitioned = array_flip($call->setUsersStateIdle($disconnectIds));
				if ($transitioned !== [])
				{
					$accessibleSenders = array_values(array_filter(
						$callUserRequest->disconnectedUsers,
						static fn ($user) => isset($transitioned[(int)($user->userId ?? 0)]),
					));
					$call->getSignaling()->sendUsersHangup($accessibleSenders);
				}
			}
		}

		Recent::scheduleUpdateCallCache($call->getId());

		return ['result' => true];
	}

	/**
	 * @restMethod call.Call.createChildCall
	 *
	 * @param DTO\CallRequest $callRequest
	 * @return array|null
	 */
	public function createChildCallAction(DTO\CallRequest $callRequest): ?array
	{
		$parentCall = Registry::getCallWithUuid($callRequest->parentCallUuid);
		if (!$parentCall)
		{
			$this->addError(new Error("call_not_found", "Call not found"));
			return [
				'result' => false,
				'errorCode' => 'call_not_found',
				'errorMessage' => 'Call not found',
			];
		}

		$currentUserId = $callRequest->userId;
		if (!$parentCall->checkAccess($currentUserId))
		{
			$this->addError(new Error("access_denied", "You do not have access to the parent call"));
			return [
				'result' => false,
				'errorCode' => 'access_denied',
				'errorMessage' => 'You do not have access to the parent call',
			];
		}

		Loader::includeModule('im');
		$childCall = $parentCall->createChildCall(
			$callRequest->roomId ?: $callRequest->callUuid,
			\Bitrix\Im\Dialog::getDialogId($callRequest->chatId, $currentUserId),
			$callRequest->provider,
			\Bitrix\Call\Call::SCHEME_JWT,
			$currentUserId
		);
		if ($childCall->hasErrors())
		{
			$this->addErrors($childCall->getErrors());
			return null;
		}

		$this->setUserStateReady($childCall, $currentUserId, $callRequest->legacyMobile);

		Recent::scheduleUpdateUsersActiveCallsCache([$currentUserId]);

		$users = array_diff($childCall->getAssociatedEntity()->getUsers(), [$currentUserId]);

		$childCall->getSignaling()->sendLogToken($currentUserId);

		$this->inviteUsers(
			call: $childCall,
			userIds: $users,
			isVideo: $callRequest->video,
		);

		$aiAvailability = CallAISettings::checkAIAvailabilityInCall();

		return [
			'callId' => $childCall->getId(),
			'autoStartAIRecording' => $childCall->autoStartRecording(),
			'AIAvailableInCall' => $aiAvailability->isSuccess(),
			'AIErrorCode' => $aiAvailability->getError()?->getCode(),
			'AIErrorMessage' => $aiAvailability->getError()?->getMessage(),
		];
	}

	/**
	 * @restMethod call.Call.invite
	 *
	 * @param DTO\UserRequest $userRequest
	 * @return true|null
	 */
	public function inviteAction(DTO\UserRequest $userRequest): ?bool
	{
		$isVideo = ($userRequest->video === "Y");
		$isShow = ($userRequest->show === "Y");
		$isLegacyMobile = ($userRequest->legacyMobile === "Y");
		$isRepeated = ($userRequest->repeated === "Y");
		$userIds = array_map('intVal', $userRequest->users);
		$callUuid = $userRequest->roomId ?: $userRequest->callUuid;

		Loader::includeModule('im');

		$call = Registry::getCallWithUuid($callUuid);
		if (!$call)
		{
			$this->addError(new Error("call_not_found", "Call not found"));
			return null;
		}

		$currentUserId = $this->getCurrentUser()->getId();
		if (!$call->checkAccess($currentUserId))
		{
			return null;
		}

		if ($call->hasErrors())
		{
			$this->addErrors($call->getErrors());
			return null;
		}

		$call->getUser($currentUserId)?->update([
			'LAST_SEEN' => new DateTime(),
			'IS_MOBILE' => ($isLegacyMobile ? 'Y' : 'N')
		]);

		$lockName = static::getLockNameWithCallId('invite', $callUuid);
		if (!Application::getConnection()->lock($lockName, static::LOCK_TTL))
		{
			$this->addError(new Error("could_not_lock", "Could not get exclusive lock"));
			return null;
		}

		// Hold the lock only for the DB-mutating prepare step; release before
		// the broadcast tail (sendInviteUsers + sendUsersInvited + recent
		// cache). Pre-incident #421808 the lock spanned the whole inviteUsers,
		// which on N=433 kept it for ~96 s and starved concurrent invites with
		// `could_not_lock`.
		try
		{
			$payload = $this->prepareInviteUsers($call, $userIds, $isRepeated);
		}
		finally
		{
			Application::getConnection()->unlock($lockName);
		}

		$this->dispatchInviteBroadcasts(
			call: $call,
			payload: $payload,
			isVideo: $isVideo,
			isLegacyMobile: $isLegacyMobile,
			isShow: $isShow,
			isRepeated: $isRepeated,
		);

		return true;
	}

	/**
	 * @restMethod call.Call.createChatForChildCall
	 *
	 * @param DTO\UserRequest $userRequest
	 * @return array|null
	 */
	public function createChatForChildCallAction(DTO\UserRequest $userRequest): ?array
	{
		Loader::includeModule('im');
		$currentUserId = $this->getCurrentUser()->getId();
		$callUuid = $userRequest->roomId ?: $userRequest->callUuid;

		$call = Registry::getCallWithUuid($callUuid);
		if (!$call)
		{
			$this->addError(new Error("call_not_found", "Call not found"));
			return null;
		}

		$lockName = static::getLockNameWithCallId('user'.$currentUserId, $callUuid);
		if (!Application::getConnection()->lock($lockName, static::LOCK_TTL))
		{
			$this->addError(new Error('could_not_lock', 'Could not get exclusive lock'));
			return null;
		}

		$users = array_merge($call->getUsers(), $userRequest->users);
		$result = \Bitrix\Im\V2\Chat\ChatFactory::getInstance()->addChat([
			'TYPE' => \Bitrix\Im\V2\Chat::IM_TYPE_CHAT,
			'AUTHOR_ID' =>$currentUserId,
			'USERS' => $users,
		]);

		if (!$result->isSuccess() || !$result->hasResult())
		{
			Application::getConnection()->unlock($lockName);
			return ['result' => false];
		}

		$chat = $result->getResult()['CHAT'];
		$chatId = $chat->getChatId();
		if (!$chatId)
		{
			Application::getConnection()->unlock($lockName);
			return ['result' => false];
		}
		$callToken = JwtCall::getCallToken($chatId, ['parentUuid' => $callUuid]);

		Application::getConnection()->unlock($lockName);

		return [
			'result' => true,
			'token' => $callToken,
			'chatId' => $chatId,
		];
	}

	/**
	 * @param \Bitrix\Call\Call $call
	 * @param int $userId
	 * @param bool $isLegacyMobile
	 */
	protected function setUserStateReady(\Bitrix\Call\Call $call, int $userId, bool $isLegacyMobile): void
	{
		$callUser = $call->getUser($userId);
		if ($callUser)
		{
			$callUser->updateState(CallUser::STATE_READY);
			$callUser->update([
				'LAST_SEEN' => new DateTime(),
				'FIRST_JOINED' => $callUser->getFirstJoined() ?: new DateTime(),
				'IS_MOBILE' => $isLegacyMobile ? 'Y' : 'N',
			]);
		}
	}

	/**
	 * @restMethod call.Call.onShareScreen
	 *
	 * @param DTO\UserRequest $userRequest
	 * @return void|null
	 */
	public function onShareScreenAction(DTO\UserRequest $userRequest)
	{
		Loader::includeModule('im');
		$callUuid = $userRequest->roomId ?: $userRequest->callUuid;

		$call = Registry::getCallWithUuid($callUuid);
		if (!$call)
		{
			$this->addError(new Error("call_not_found", "Call not found"));
			return null;
		}

		$currentUserId = $this->getCurrentUser()->getId();
		if (!$call->checkAccess($currentUserId))
		{
			return null;
		}

		$callUser = $call->getUser($currentUserId);
		if ($callUser)
		{
			$callUser->update([
				'SHARED_SCREEN' => 'Y'
			]);
		}
	}

	/**
	 * @restMethod call.Call.onStartRecord
	 *
	 * @param DTO\UserRequest $userRequest
	 * @return void|null
	 */
	public function onStartRecordAction(DTO\UserRequest $userRequest)
	{
		Loader::includeModule('im');
		$callUuid = $userRequest->roomId ?: $userRequest->callUuid;

		$call = Registry::getCallWithUuid($callUuid);
		if (!$call)
		{
			$this->addError(new Error("call_not_found", "Call not found"));
			return null;
		}

		$currentUserId = $this->getCurrentUser()->getId();
		if (!$call->checkAccess($currentUserId))
		{
			return null;
		}

		$callUser = $call->getUser($currentUserId);
		if ($callUser)
		{
			$callUser->update([
				'RECORDED' => 'Y'
			]);
		}
	}

	/**
	 * @restMethod call.Call.tryJoinCall
	 * @param DTO\UserRequest $userRequest
	 * @return array|null
	 */
	public function tryJoinCallAction(DTO\UserRequest $userRequest): ?array
	{
		Loader::includeModule('im');
		$currentUserId = $this->getCurrentUser()->getId();
		$call = CallFactory::searchActiveCall(
			$userRequest->callType,
			$userRequest->provider,
			$userRequest->entityType,
			$userRequest->entityId,
			$currentUserId
		);
		if (!$call)
		{
			return ['success' => false];
		}

		if ($call->hasErrors())
		{
			$this->addErrors($call->getErrors());
			return null;
		}

		if (!$call->getAssociatedEntity()->checkAccess($currentUserId))
		{
			$this->addError(new Error('access_denied', "You can not access this call"));
			return null;
		}

		if (!$call->hasUser($currentUserId))
		{
			$addedUser = $call->addUser($currentUserId);
			if (!$addedUser)
			{
				$this->addError(new Error("user_limit_reached", "User limit reached"));
				return null;
			}
			$call->getSignaling()->sendUsersJoined($currentUserId, [$currentUserId]);
		}

		Recent::scheduleUpdateCallCache($call->getId());

		return array_merge(
			['success' => true],
			$this->formatCallResponse($call)
		);
	}

	protected static function getLockNameWithCallId(string $prefix, string $callUuid): string
	{
		if (!empty($prefix) && !empty($callUuid))
		{
			return "{$prefix}_call_{$callUuid}";
		}

		return '';
	}

	/**
	 * @param \Bitrix\Call\Call $call
	 * @param bool $isNew
	 * @return array{call: array, connectionData: array, users: array, userData: array, publicChannels: array, logToken: string, isNew: bool}
	 */
	protected function formatCallResponse(\Bitrix\Call\Call $call, int $initiatorId = 0, bool $isNew = false): array
	{
		$currentUserId = $this->getCurrentUser()->getId();

		$users = $call->getUsers();
		$publicChannels = Loader::includeModule('pull')
			? \Bitrix\Pull\Channel::getPublicIds([
				'TYPE' => \CPullChannel::TYPE_PRIVATE,
				'USERS' => $users,
				'JSON' => true
			])
			: []
		;

		$callToken = '';
		if ($call->getChatId() > 0)
		{
			$callToken = JwtCall::getCallToken($call->getChatId());
		}

		$response = [
			'call' => $call->toArray($initiatorId),
			'connectionData' => $call->getConnectionData($currentUserId),
			'users' => $users,
			'userData' => Util::getUsers($users),
			'publicChannels' => $publicChannels,
			'logToken' => $call->getLogToken($currentUserId),
			'callToken' => $callToken,
		];
		if ($isNew)
		{
			$response['isNew'] = $isNew;
		}

		return $response;
	}
}