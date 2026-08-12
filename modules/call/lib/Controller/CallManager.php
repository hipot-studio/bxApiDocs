<?php

namespace Bitrix\Call\Controller;

use Bitrix\Call\CallUser;
use Bitrix\Call\Integration\EntityType;
use Bitrix\Im\Common;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Main\Application;
use Bitrix\Main\Engine;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Localization\Loc;
use Bitrix\Call\Recent;
use Bitrix\Call\Settings;
use Bitrix\Call\CallFactory;
use Bitrix\Call\NotifyService;
use Bitrix\Call\Call\Registry;
use Bitrix\Call\Integration\AI\CallAISettings;

/**
 * @internal
 */
class CallManager extends Engine\Controller
{
	protected const LOCK_TTL = 10; // in seconds
	private const VALID_TRUE_VALUES = [true, 1, '1', 'Y', 'y', 'true'];

	/**
	 * @restMethod call.CallManager.create
	 * @param int $type
	 * @param string $provider
	 * @param string $entityType
	 * @param string $entityId
	 * @param bool $joinExisting
	 * @return array|null
	 *
	 */
	public function createAction(int $type, string $provider, string $entityType, string $entityId, bool $joinExisting = false): ?array
	{
		$currentUserId = $this->getCurrentUser()->getId();

		$call = null;

		$lockName = static::getLockNameWithEntityId($entityType, $entityId, $currentUserId);
		if (!Application::getConnection()->lock($lockName, 3))
		{
			if ($joinExisting)
			{
				$call = CallFactory::searchActive($type, $provider, $entityType, $entityId);
			}

			if (!$call)
			{
				$this->addError(new Error("Could not get exclusive lock", "could_not_lock"));
				return null;
			}
		}

		if (!$call && $joinExisting)
		{
			$call = CallFactory::searchActive($type, $provider, $entityType, $entityId);
		}

		// Check if target user is busy (for PROVIDER_PLAIN)
		$targetUserIsBusy = false;
		if (!$call && ($provider == \Bitrix\Call\Call::PROVIDER_PLAIN))
		{
			if (CallFactory::hasUserActiveCalls((int)$entityId, $currentUserId))
			{
				$targetUserIsBusy = true;

				// Send busy notification to initiator
				$chat = ChatFactory::getInstance()->getPrivateChat($currentUserId, (int)$entityId);
				if ($chat->getId() > 0)
				{
					$notifyService = NotifyService::getInstance();
					$notifyService->sendOpponentBusyMessage($currentUserId, (int)$entityId);
				}
			}
		}

		$isNew = false;
		try
		{
			if ($call)
			{
				if ($call->hasErrors())
				{
					$this->addErrors($call->getErrors());
					Application::getConnection()->unlock($lockName);
					return null;
				}

				if (!$call->getAssociatedEntity()->checkAccess($currentUserId))
				{
					if ($call instanceof \Bitrix\Call\Call\PlainCall)
					{
						$chat = ChatFactory::getInstance()->getPrivateChat($currentUserId, (int)$entityId);
						if ($chat->getId() > 0)
						{
							$notifyService = NotifyService::getInstance();
							$notifyService->sendOpponentBusyMessage($currentUserId, (int)$entityId);
						}

						$this->addError(new Error('User is currently busy on another call', 'user_is_busy'));
						Application::getConnection()->unlock($lockName);
						return null;
					}

					$this->addError(new Error('You can not access this call', 'access_denied'));
					Application::getConnection()->unlock($lockName);
					return null;
				}

				if (!$call->hasUser($currentUserId))
				{
					$addedUser = $call->addUser($currentUserId);

					if (!$addedUser)
					{
						$this->addError(new Error("User limit reached", "user_limit_reached"));
						Application::getConnection()->unlock($lockName);
						return null;
					}
				}
			}
			else
			{
				$isNew = true;

				// Terminate any stuck 1-1 calls of the initiator before creating a new one.
				// Skipped when the target is already known to be busy: in that case we only
				// create a technical "busy" call and return user_is_busy, so cleaning up the
				// initiator's calls here would needlessly tear down an ongoing live 1-1.
				if ($provider === \Bitrix\Call\Call::PROVIDER_PLAIN && !$targetUserIsBusy)
				{
					Recent::terminateStuckPlainCallsForUser($currentUserId);
				}

				try
				{
					$call = CallFactory::createWithEntity(
						type: $type,
						provider: $provider,
						entityType: $entityType,
						entityId: $entityId,
						initiatorId: $currentUserId,
						scheme: \Bitrix\Call\Call::SCHEME_CLASSIC,
					);
				}
				catch (\Throwable $e)
				{
					$this->addError(new Error($e->getMessage(), $e->getCode()));
					Application::getConnection()->unlock($lockName);
					return null;
				}

				if ($call->hasErrors())
				{
					$this->addErrors($call->getErrors());
					Application::getConnection()->unlock($lockName);
					return null;
				}

				if (!$call->getAssociatedEntity()->canStartCall($currentUserId))
				{
					$this->addError(new Error("You can not create this call", 'access_denied'));
					Application::getConnection()->unlock($lockName);
					return null;
				}

				$initiator = $call->getUser($currentUserId);
				$initiator->updateState(CallUser::STATE_READY);
				$initiator->update([
					'LAST_SEEN' => new DateTime(),
					'FIRST_JOINED' => new DateTime()
				]);

				// If target user is busy, add them to call with STATE_BUSY and finish immediately
				if ($targetUserIsBusy && $provider == \Bitrix\Call\Call::PROVIDER_PLAIN)
				{
					$targetUserId = (int)$entityId;
					$targetUser = $call->addUser($targetUserId);
					if ($targetUser)
					{
						$targetUser->updateState(CallUser::STATE_BUSY);
					}

					// Finish call immediately so it won't be found by searchActive next time
					$call->setActionUserId($currentUserId)->finish();
				}

			}
		}
		catch(\Exception $e)
		{
			$this->addError(new Error(
				"Can't initiate a call. Server error. (" . ($status ?? "") . ")",
				"call_init_error")
			);

			Application::getConnection()->unlock($lockName);
			return null;
		}

		Application::getConnection()->unlock($lockName);

		// Return error if target user is busy (but call was already created for logging)
		if ($targetUserIsBusy && $provider == \Bitrix\Call\Call::PROVIDER_PLAIN)
		{
			$this->addError(new Error('User is currently busy on another call', 'user_is_busy'));
			return null;
		}

		return $this->formatCallResponse($call, 0, $isNew);
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

		$response = [
			'call' => $call->toArray($initiatorId),
			'connectionData' => $call->getConnectionData($currentUserId),
			'users' => $users,
			'userData' => $call->prepareUserData($users),
			'publicChannels' => $publicChannels,
			'logToken' => $call->getLogToken($currentUserId),
		];
		if ($isNew)
		{
			$response['isNew'] = $isNew;
		}
		if (Settings::isAIServiceEnabled())
		{
			$response['ai'] = [
				'serviceEnabled' => Settings::isAIServiceEnabled(),
				'settingsEnabled' => CallAISettings::isEnableBySettings(),
				'recordingMinUsers' => CallAISettings::getRecordMinUsers(),
				'agreementAccepted' => CallAISettings::isAgreementAccepted(),
				'tariffAvailable' => CallAISettings::isTariffAvailable(),
				'baasAvailable' => CallAISettings::baasAvailable(),
			];
		}

		return $response;
	}

	/**
	 * @restMethod call.CallManager.createChildCall
	 * @param int $parentId
	 * @param string $newProvider
	 * @param int[] $newUsers
	 * @return array|null
	 */
	public function createChildCallAction(int $parentId, string $newProvider, array $newUsers): ?array
	{
		$parentCall = Registry::getCallWithId($parentId);
		if (!$parentCall)
		{
			$this->addError(new Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
			return null;
		}

		$currentUserId = $this->getCurrentUser()->getId();
		if (!$this->checkCallAccess($parentCall, $currentUserId))
		{
			$this->addError(new Error("You do not have access to the parent call", "access_denied"));
			return null;
		}

		$childCall = $parentCall->makeClone($newProvider);
		if ($childCall->hasErrors())
		{
			$this->addErrors($childCall->getErrors());
			return null;
		}

		$initiator = $childCall->getUser($currentUserId);
		$initiator->updateState(CallUser::STATE_READY);
		$initiator->updateLastSeen(new DateTime());

		foreach ($newUsers as $userId)
		{
			if (!$childCall->hasUser($userId))
			{
				$childCall->addUser($userId)?->updateState(CallUser::STATE_CALLING);
			}
		}

		$users = $childCall->getUsers();

		return [
			'call' => $childCall->toArray(),
			'connectionData' => $childCall->getConnectionData($currentUserId),
			'users' => $users,
			'userData' => $childCall->prepareUserData($users),
			'logToken' => $childCall->getLogToken($currentUserId)
		];
	}

	/**
	 * @restMethod call.CallManager.tryJoinCall
	 * @param int $type
	 * @param string $provider
	 * @param string $entityType
	 * @param string $entityId
	 * @return array|null
	 */
	public function tryJoinCallAction(int $type, string $provider, string $entityType, string $entityId): ?array
	{
		$call = CallFactory::searchActive($type, $provider, $entityType, $entityId);
		if (!$call)
		{
			return ['success' => false];
		}

		if ($call->hasErrors())
		{
			$this->addErrors($call->getErrors());
			return null;
		}

		$currentUserId = $this->getCurrentUser()->getId();
		if (!$call->getAssociatedEntity()->checkAccess($currentUserId))
		{
			$this->addError(new Error("You can not access this call", 'access_denied'));
			return null;
		}

		if (!$call->hasUser($currentUserId))
		{
			$addedUser = $call->addUser($currentUserId);
			if (!$addedUser)
			{
				$this->addError(new Error("User limit reached",  "user_limit_reached"));
				return null;
			}
			$call->getSignaling()->sendUsersJoined($currentUserId, [$currentUserId]);
		}

		return array_merge(
			['success' => true],
			$this->formatCallResponse($call)
		);
	}

	/**
	 * @restMethod call.CallManager.interrupt
	 * @param int $callId
	 * @return array|null
	 */
	public function interruptAction(int $callId): ?array
	{
		$call = Registry::getCallWithId($callId);
		if (!$call)
		{
			$this->addError(new Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
			return null;
		}

		$currentUserId = $this->getCurrentUser()->getId();
		if (!$this->checkCallAccess($call, $currentUserId))
		{
			$this->addError(new Error("You do not have access to the parent call", "access_denied"));
			return null;
		}

		$call->setActionUserId($currentUserId)->finish();

		return [
			'call' => $call->toArray($currentUserId),
			'connectionData' => $call->getConnectionData($currentUserId),
			'logToken' => $call->getLogToken($currentUserId)
		];
	}

	/**
	 * @restMethod call.CallManager.get
	 * @param int $callId
	 * @return array|null
	 */
	public function getAction(int $callId): ?array
	{
		$call = Registry::getCallWithId($callId);
		if (!$call)
		{
			$this->addError(new Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
			return null;
		}

		$currentUserId = $this->getCurrentUser()->getId();
		if (!$this->checkCallAccess($call, $currentUserId))
		{
			$this->addError(new Error("You do not have access to the parent call", "access_denied"));
			return null;
		}

		return $this->formatCallResponse($call, $currentUserId);
	}

	/**
	 * @restMethod call.CallManager.invite
	 * @param int $callId
	 * @param int[] $userIds
	 * @param string $video
	 * @param string $show
	 * @param string $legacyMobile
	 * @param string $repeated
	 * @return true|null
	 */
	public function inviteAction(int $callId, array $userIds, $video = "N", $show = "Y", $legacyMobile = "N", $repeated = "N"): ?bool
	{
		$isVideo = ($video === "Y");
		$isShow = ($show === "Y");
		$isLegacyMobile = ($legacyMobile === "Y");
		$isRepeated = ($repeated === "Y");
		$userIds = array_map('intVal', $userIds);

		// Acquire lock first to prevent race conditions
		$lockName = static::getLockNameWithCallId('invite', $callId);
		if (!Application::getConnection()->lock($lockName, static::LOCK_TTL))
		{
			$this->addError(new Error("Could not get exclusive lock", "could_not_lock"));
			return null;
		}

		Registry::clearCache($callId);
		$call = Registry::getCallWithId($callId);
		if (!$call)
		{
			Application::getConnection()->unlock($lockName);
			$this->addError(new Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
			return null;
		}

		// Check if call is already finished
		if ($call->getState() === \Bitrix\Call\Call::STATE_FINISHED)
		{
			Application::getConnection()->unlock($lockName);
			return null;
		}

		$currentUserId = $this->getCurrentUser()->getId();
		if (!$this->checkCallAccess($call, $currentUserId))
		{
			Application::getConnection()->unlock($lockName);
			return null;
		}

		if ($call->hasErrors())
		{
			$this->addErrors($call->getErrors());
			Application::getConnection()->unlock($lockName);
			return null;
		}

		$call->getUser($currentUserId)->update([
			'LAST_SEEN' => new DateTime(),
			'IS_MOBILE' => ($isLegacyMobile ? 'Y' : 'N')
		]);

		if ($call->getState() !== \Bitrix\Call\Call::STATE_FINISHED)
		{
			$this->inviteUsers($call, $userIds, $isLegacyMobile, $isVideo, $isShow, $isRepeated);
		}

		Application::getConnection()->unlock($lockName);

		return true;
	}

	protected function inviteUsers(\Bitrix\Call\Call $call, $userIds, $isLegacyMobile, $isVideo, $isShow, $isRepeated): void
	{
		if (\Bitrix\Im\User::getInstance()->isExtranet())
		{
			$userIds = \Bitrix\Im\Integration\Socialnetwork\Extranet::filterUserList($userIds, \Bitrix\Im\User::getInstance()->getId()) ?: [];
		}

		$usersToInvite = [];
		$existingUsers = [];
		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;
			if (!$userId)
			{
				continue;
			}
			if (!$call->hasUser($userId))
			{
				if (!$call->addUser($userId))
				{
					continue;
				}
			}
			else if ($isRepeated === false && $call->getAssociatedEntity())
			{
				$existingUsers[] = $userId;
			}
			$usersToInvite[] = $userId;
			$callUser = $call->getUser($userId);
			if($callUser->getState() != CallUser::STATE_READY)
			{
				$callUser->updateState(CallUser::STATE_CALLING);
			}
		}

		if (!empty($existingUsers))
		{
			$call->getAssociatedEntity()->onExistingUsersInvite($existingUsers);
		}

		if (count($usersToInvite) === 0)
		{
			$this->addError(new Error("No users to invite", "empty_users"));
			return;
		}

		$sendPush = $isRepeated !== true;

		$call->sendInviteUsers(
			senderId: $this->getCurrentUser()->getId(),
			toUserIds: $usersToInvite,
			isLegacyMobile: $isLegacyMobile,
			video: $isVideo,
			sendPush: $sendPush,
			isRepeated: $isRepeated,
		);

		// send userInvited to everyone else.
		$allUsers = $call->getUsers();
		$otherUsers = array_diff($allUsers, $userIds);
		$call->getSignaling()->sendUsersInvited(
			$this->getCurrentUser()->getId(),
			$otherUsers,
			$usersToInvite,
			$isShow
		);

		if ($call->getState() === \Bitrix\Call\Call::STATE_NEW)
		{
			$call->updateState(\Bitrix\Call\Call::STATE_INVITING);
		}
	}

	/**
	 * @restMethod call.CallManager.cancel
	 * @param int $callId
	 * @return void|null
	 */
	public function cancelAction(int $callId)
	{
		$call = Registry::getCallWithId($callId);
		if (!$call)
		{
			$this->addError(new Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
			return null;
		}

		$currentUserId = $this->getCurrentUser()->getId();
		if (!$this->checkCallAccess($call, $currentUserId))
		{
			return null;
		}
	}

	/**
	 * @restMethod call.CallManager.answer
	 * @param int $callId
	 * @param int $callInstanceId
	 * @param string $legacyMobile
	 * @return void|null
	 */
	public function answerAction(int $callId, $callInstanceId, $legacyMobile = "N")
	{
		$isLegacyMobile = $legacyMobile === "Y";
		$call = Registry::getCallWithId($callId);
		if (!$call)
		{
			$this->addError(new Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
			return null;
		}

		$currentUserId = $this->getCurrentUser()->getId();
		if (!$this->checkCallAccess($call, $currentUserId))
		{
			return null;
		}

		$callUser = $call->getUser($currentUserId);
		if ($callUser)
		{
			$lockName = static::getLockNameWithCallId('user' . $currentUserId, $callId);
			if (!Application::getConnection()->lock($lockName, static::LOCK_TTL))
			{
				$this->addError(new Error("Could not get exclusive lock", "could_not_lock"));
				return null;
			}

			Registry::clearCache($callId);
			$call = Registry::getCallWithId($callId);
			if (!$call)
			{
				Application::getConnection()->unlock($lockName);
				$this->addError(new Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
				return null;
			}

			// Check if call is already finished
			if ($call->getState() === \Bitrix\Call\Call::STATE_FINISHED)
			{
				Application::getConnection()->unlock($lockName);
				return null;
			}

			$callUser->updateState(CallUser::STATE_READY);
			$callUser->update([
				'LAST_SEEN' => new DateTime(),
				'FIRST_JOINED' => $callUser->getFirstJoined() ?: new DateTime(),
				'IS_MOBILE' => $isLegacyMobile ? 'Y' : 'N',
			]);
		}

		Application::getConnection()->unlock($lockName);

		$call->getSignaling()->sendAnswer($currentUserId, $callInstanceId, $isLegacyMobile);
	}

	/**
	 * @restMethod call.CallManager.decline
	 * @param int $callId
	 * @param int $callInstanceId
	 * @param int $code
	 * @return void|null
	 */
	public function declineAction(int $callId, $callInstanceId, int $code = 603)
	{
		$currentUserId = $this->getCurrentUser()->getId();
		$call = Registry::getCallWithId($callId);
		if (!$call)
		{
			$this->addError(new Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
			return null;
		}

		if (!$this->checkCallAccess($call, $currentUserId))
		{
			return null;
		}

		$callUser = $call->getUser($currentUserId);
		if (!$callUser)
		{
			$this->addError(new Error("User is not part of the call", "unknown_call_user"));
			return null;
		}

		if ($callUser->getState() === CallUser::STATE_READY)
		{
			$this->addError(new Error("Can not decline in {$callUser->getState()} user state", "wrong_user_state"));
			return null;
		}

		$lockName = static::getLockNameWithCallId('user'.$currentUserId, $callId);
		if (!Application::getConnection()->lock($lockName, static::LOCK_TTL))
		{
			$this->addError(new Error("Could not get exclusive lock", "could_not_lock"));
			return null;
		}

		// Clear cache to force fresh DB load
		Registry::clearCache($callId);
		$call = Registry::getCallWithId($callId);

		// Check if call is already finished
		if ($call->getState() === \Bitrix\Call\Call::STATE_FINISHED)
		{
			Application::getConnection()->unlock($lockName);
			return null;
		}

		// Reload user object from fresh call data
		$callUser = $call->getUser($currentUserId);
		if (!$callUser)
		{
			Application::getConnection()->unlock($lockName);
			return null;
		}

		if ($callUser->getState() === CallUser::STATE_READY)
		{
			Application::getConnection()->unlock($lockName);
			$this->addError(new Error("Can not decline in {$callUser->getState()} user state", "wrong_user_state"));
			return null;
		}

		if ($code === 486)
		{
			$callUser->updateState(CallUser::STATE_BUSY);
		}
		else
		{
			$callUser->updateState(CallUser::STATE_DECLINED);
		}
		$callUser->updateLastSeen(new DateTime());

		Application::getConnection()->unlock($lockName);

		$userIds = array_values($call->getUsers());
		$call->getSignaling()->sendHangup($currentUserId, $userIds, $callInstanceId, $code);

		if (!$call->hasActiveUsers(false))
		{
			$call->setActionUserId($currentUserId)->finish();
		}
	}

	/**
	 * @restMethod call.CallManager.ping
	 * @param int $callId
	 * @param int $requestId
	 * @param bool $retransmit
	 * @return bool
	 */
	public function pingAction(int $callId, $requestId, $retransmit = true)
	{
		$call = Registry::getCallWithId($callId);
		if (!$call)
		{
			$this->addError(new Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
			return null;
		}

		$currentUserId = $this->getCurrentUser()->getId();
		if (!$this->checkCallAccess($call, $currentUserId))
		{
			return null;
		}

		$callUser = $call->getUser($currentUserId);
		if ($callUser)
		{
			$callUser->updateLastSeen(new DateTime());
			if ($callUser->getState() == CallUser::STATE_UNAVAILABLE)
			{
				$callUser->updateState(CallUser::STATE_IDLE);
			}
		}

		if (
			is_bool($retransmit) && $retransmit===true
			|| is_string($retransmit) && in_array($retransmit, ['true', 'Y', '1'], true)
		)
		{
			$call->getSignaling()->sendPing($currentUserId, $requestId);
		}

		return true;
	}

	/**
	 * @restMethod call.CallManager.onShareScreen
	 * @param int $callId
	 * @return void|null
	 */
	public function onShareScreenAction(int $callId)
	{
		$call = Registry::getCallWithId($callId);
		if (!$call)
		{
			$this->addError(new Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
			return null;
		}

		$currentUserId = $this->getCurrentUser()->getId();
		if (!$this->checkCallAccess($call, $currentUserId))
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
	 * @restMethod call.CallManager.onStartRecord
	 * @param int $callId
	 * @return void|null
	 */
	public function onStartRecordAction(int $callId)
	{
		$call = Registry::getCallWithId($callId);
		if (!$call)
		{
			$this->addError(new Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
			return null;
		}

		$currentUserId = $this->getCurrentUser()->getId();
		if (!$this->checkCallAccess($call, $currentUserId))
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
	 * @restMethod call.CallManager.negotiationNeeded
	 * @param int $callId
	 * @param int $userId
	 * @param bool $restart
	 * @return void|null
	 */
	public function negotiationNeededAction(int $callId, int $userId, $restart = false)
	{
		$restart = (bool)$restart;
		$call = Registry::getCallWithId($callId);
		if (!$call)
		{
			$this->addError(new Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
			return null;
		}

		$currentUserId = $this->getCurrentUser()->getId();
		if (!$this->checkCallAccess($call, $currentUserId))
		{
			return null;
		}

		$callUser = $call->getUser($currentUserId);
		if ($callUser)
		{
			$callUser->updateLastSeen(new DateTime());
		}

		$call->getSignaling()->sendNegotiationNeeded($currentUserId, $userId, $restart);
	}

	/**
	 * @restMethod call.CallManager.connectionOffer
	 * @param int $callId
	 * @param int $userId
	 * @param int $connectionId
	 * @param string $sdp
	 * @param string $userAgent
	 * @return void|null
	 */
	public function connectionOfferAction(int $callId, int $userId, $connectionId, $sdp, $userAgent)
	{
		$call = Registry::getCallWithId($callId);
		if (!$call)
		{
			$this->addError(new Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
			return null;
		}

		$currentUserId = $this->getCurrentUser()->getId();
		if (!$this->checkCallAccess($call, $currentUserId))
		{
			return null;
		}

		$callUser = $call->getUser($currentUserId);
		if ($callUser)
		{
			$callUser->updateLastSeen(new DateTime());
		}

		$call->getSignaling()->sendConnectionOffer($currentUserId, $userId, $connectionId, $sdp, $userAgent);
	}

	/**
	 * @restMethod call.CallManager.connectionAnswer
	 * @param int $callId
	 * @param int $userId
	 * @param int $connectionId
	 * @param string $sdp
	 * @param string $userAgent
	 * @return void|null
	 */
	public function connectionAnswerAction(int $callId, int $userId, $connectionId, $sdp, $userAgent)
	{
		$currentUserId = $this->getCurrentUser()->getId();
		$call = Registry::getCallWithId($callId);
		if (!$call)
		{
			$this->addError(new Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
			return null;
		}

		if (!$this->checkCallAccess($call, $currentUserId))
		{
			return null;
		}

		$callUser = $call->getUser($currentUserId);
		if ($callUser)
		{
			$callUser->updateLastSeen(new DateTime());
		}

		$call->getSignaling()->sendConnectionAnswer($currentUserId, $userId, $connectionId, $sdp, $userAgent);
	}

	/**
	 * @restMethod call.CallManager.iceCandidate
	 * @param int $callId
	 * @param int $userId
	 * @param int $connectionId
	 * @param array $candidates
	 * @return void|null
	 */
	public function iceCandidateAction(int $callId, int $userId, $connectionId, array $candidates)
	{
		// mobile can alter key order, so we recover it
		ksort($candidates);

		$currentUserId = $this->getCurrentUser()->getId();
		$call = Registry::getCallWithId($callId);

		if (!$this->checkCallAccess($call, $currentUserId))
		{
			return null;
		}

		$callUser = $call->getUser($currentUserId);
		if ($callUser)
		{
			$callUser->updateLastSeen(new DateTime());
		}

		$call->getSignaling()->sendIceCandidates($currentUserId, $userId, $connectionId, $candidates);
	}

	/**
	 * @restMethod call.CallManager.hangup
	 * @param int $callId
	 * @param int $callInstanceId
	 * @param bool $retransmit
	 * @return void|null
	 */
	public function hangupAction(int $callId, $callInstanceId, $retransmit = true)
	{
		$currentUserId = $this->getCurrentUser()->getId();

		$lockName = static::getLockNameWithCallId('user'.$currentUserId, $callId);
		if (!Application::getConnection()->lock($lockName, static::LOCK_TTL))
		{
			$this->addError(new Error("Could not get exclusive lock", "could_not_lock"));
			return null;
		}

		$call = Registry::getCallWithId($callId);
		if (!$call)
		{
			$this->addError(new Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
			return null;
		}

		if (!$this->checkCallAccess($call, $currentUserId))
		{
			return null;
		}

		$callUser = $call->getUser($currentUserId);
		if ($callUser)
		{
			$callUser->updateState(CallUser::STATE_IDLE);
			$callUser->updateLastSeen(new DateTime());
		}

		if (
			is_bool($retransmit) && $retransmit===true
			|| is_string($retransmit) && in_array($retransmit, ['true', 'Y', '1'], true)
		)
		{
			$userIds = $call->getUsers();
			$call->getSignaling()->sendHangup($currentUserId, $userIds, $callInstanceId);
		}

		if (!$this->hasActiveUsers($call))
		{
			$call->setActionUserId($currentUserId)->finish();
		}

		Application::getConnection()->unlock($lockName);
	}

	private function hasActiveUsers(\Bitrix\Call\Call $call)
	{
		if (static::isPlainCall($call))
		{
			return $call->getState() === \Bitrix\Call\Call::STATE_FINISHED;
		}
		else
		{
			return $call->hasActiveUsers();
		}
	}

	/**
	 * @restMethod call.CallManager.finish
	 * @param int|null $callId
	 * @param string|null $callUuid
	 * @param string $silent Suppresses chat messages on finish; pass "Y" / "1" / "true".
	 * @return array|null
	 */
	public function finishAction(?int $callId = null, ?string $callUuid = null, string $silent = 'N'): ?array
	{
		$silent = in_array($silent, self::VALID_TRUE_VALUES, true);
		$call = $callId
			? Registry::getCallWithId($callId)
			: ($callUuid ? Registry::getCallWithUuid($callUuid) : null);

		if (!$call)
		{
			$this->addError(new Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
			return null;
		}

		$currentUserId = $this->getCurrentUser()->getId();
		if (!$this->checkCallAccess($call, $currentUserId))
		{
			$this->addError(new Error("You do not have access to the call", "access_denied"));
			return null;
		}

		// UUID-based lock to serialize with Controller\Call::answerAction and
		// Controller\Call::finishCallAction on the JWT scheme.
		$lockName = "call_state_call_{$call->getUuid()}";
		if (!Application::getConnection()->lock($lockName, static::LOCK_TTL))
		{
			$this->addError(new Error("Could not get exclusive lock", "could_not_lock"));
			return null;
		}

		try
		{
			Registry::clearCache($call->getId());
			$call = Registry::getCallWithId($call->getId());
			if (!$call)
			{
				$this->addError(new Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
				return null;
			}

			if ($call->getState() === \Bitrix\Call\Call::STATE_FINISHED)
			{
				// Already finished: re-broadcast finish pull and refresh Recent
				// so stuck clients still showing the call can clean up.
				// Pass $forRecovery=true to bypass the  suppression — without it any recovery attempt that lands
				// inside the 10s window after the original Call::finish would silently no-op.
				$call->setActionUserId($currentUserId)
					->getSignaling()
					->sendFinish(forRecovery: true)
				;
			}
			else
			{
				$call
					->setActionUserId($currentUserId)
					->setSilentFinish($silent)
					->finish()
				;
				Recent::terminateAllCallsInChat($call->getChatId(), $call->getId());
			}
		}
		finally
		{
			if ($call instanceof \Bitrix\Call\Call)
			{
				Recent::updateCallCache($call->getId());
			}
			Application::getConnection()->unlock($lockName);
		}

		return [
			'call' => $call->toArray($currentUserId),
			'connectionData' => $call->getConnectionData($currentUserId),
			'logToken' => $call->getLogToken($currentUserId),
		];
	}

	/**
	 * @restMethod call.CallManager.getUsers
	 * @param int $callId
	 * @param int[] $userIds
	 * @return null|array
	 */
	public function getUsersAction(int $callId, array $userIds = [])
	{
		$call = Registry::getCallWithId($callId);
		if (!$call)
		{
			$this->addError(new Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
			return null;
		}

		$currentUserId = $this->getCurrentUser()->getId();
		if (!$this->checkCallAccess($call, $currentUserId))
		{
			$this->addError(new Error("You do not have access to the call", "access_denied"));
			return null;
		}

		if (empty($userIds))
		{
			$allowedUserIds = $call->getUsers();
		}
		else
		{
			$allowedUserIds = array_filter($userIds, function($userId) use ($call, $currentUserId)
			{
				return $userId == $currentUserId || $call->hasUser($userId);
			});
		}

		if (empty($allowedUserIds))
		{
			$this->addError(new Error("Users are not part of the call", "access_denied"));
			return null;
		}

		return $call->prepareUserData($allowedUserIds);
	}

	/**
	 * @restMethod call.CallManager.getUserState
	 * @param int|null $callId
	 * @param string|null $callUuid
	 * @param int $userId
	 * @return null|array
	 */
	public function getUserStateAction(?int $callId = null, ?string $callUuid = null, int $userId = 0)
	{
		$currentUserId = (int)$this->getCurrentUser()->getId();
		$call = $callId
			? Registry::getCallWithId($callId)
			: ($callUuid ? Registry::getCallWithUuid($callUuid) : null);

		if (!$call || !$this->checkCallAccess($call, $currentUserId))
		{
			$this->addError(new Error("Call is not found or you do not have access to the call", "access_denied"));
			return null;
		}

		if ($userId === 0)
		{
			$userId = $currentUserId;
		}

		$callUser = $call->getUser($userId);
		if (!$callUser)
		{
			$this->addError(new Error("User is not part of the call", "unknown_call_user"));
			return null;
		}

		return $callUser->toArray();
	}

	/**
	 * @restMethod call.CallManager.getCallLimits
	 * @return array
	 */
	public function getCallLimitsAction(): array
	{
		return [
			'callServerEnabled' => Settings::isCallServerEnabled(),
			'maxParticipants' => Settings::getMaxParticipants(),
		];
	}

	/**
	 * @restMethod call.CallManager.reportConnectionStatus
	 * @param int $callId
	 * @param bool $connectionStatus
	 * @return void
	 */
	public function reportConnectionStatusAction(int $callId, bool $connectionStatus): void
	{
		AddEventToStatFile('im', 'call_connection', $callId, ($connectionStatus ? 'Y' : 'N'));
	}

	protected function checkCallAccess(\Bitrix\Call\Call $call, $userId)
	{
		if (!$call->checkAccess($userId))
		{
			$this->addError(new Error("You don't have access to the call " . $call->getId() . "; (current user id: " . $userId . ")", 'access_denied'));
			return false;
		}

		return true;
	}

	protected static function getLockNameWithEntityId(string $entityType, $entityId, $currentUserId): string
	{
		if ($entityType === EntityType::CHAT && (Common::isChatId($entityId) || (int)$entityId > 0))
		{
			$chatId = \Bitrix\Im\Dialog::getChatId($entityId, $currentUserId);

			return "call_entity_{$entityType}_{$chatId}";
		}

		return "call_entity_{$entityType}_{$entityId}";
	}

	protected static function getLockNameWithCallId(string $prefix, int|string $callId): string
	{
		$call = Registry::getCallWithId($callId);
		if (static::isPlainCall($call))
		{
			$prefix = '';
		}
		return "{$prefix}_call_{$callId}";
	}

	/**
	 * Check if call is 1-on-1 (direct call between two users)
	 * For 1-on-1 calls, we use call-level locking instead of per-user locking
	 *
	 * @param \Bitrix\Call\Call $call
	 * @return bool
	 */
	private static function isPlainCall(\Bitrix\Call\Call $call): bool
	{
		return $call->getProvider() === \Bitrix\Call\Call::PROVIDER_PLAIN;
	}

	public function configureActions(): array
	{
		return [
			'getUsers' => [
				'+prefilters' => [new Engine\ActionFilter\CloseSession()],
			],
			'reportConnectionStatus' => [
				'+prefilters' => [new Engine\ActionFilter\CloseSession()],
			],
		];
	}
}