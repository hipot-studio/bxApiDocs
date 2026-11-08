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
use Bitrix\Call\Service\GuestCallPolicy;
use Bitrix\Call\Settings;
use Bitrix\Call\CallFactory;
use Bitrix\Call\NotifyService;
use Bitrix\Call\Call\Registry;
use Bitrix\Call\RingingDeviceRegistry;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Idempotence;
use Bitrix\Call\ControllerClient;
use Bitrix\Call\Logger\Logger;

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
		// Checked before the lock and any lookup: an unsupported provider would be stored
		// with the call and broadcast to clients that do not know the value.
		if (!\Bitrix\Call\Call::isProviderSupported($provider))
		{
			$this->addInvalidProviderError();
			return null;
		}

		$currentUserId = $this->getCurrentUser()->getId();

		// Ahead of every lookup below: resolving a bare user id into a call entity creates the private
		// chat of the two, so a refusal issued any later would already have left that chat behind.
		if (!(new GuestCallPolicy())->canStartCallInEntity((int)$currentUserId, $entityType, $entityId))
		{
			$this->addError(new Error('Guests can only start a call in a chat of their own', 'guest_access_denied'));

			return null;
		}

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

				// Only starting a call follows the portal switch: the same action also joins a call already
				// running in the chat ($joinExisting), which is why the check sits here and not in a prefilter.
				if (!(new GuestCallPolicy())->canStartCall((int)$currentUserId))
				{
					$this->addError(
						new Error('Starting a call is not available for guest users on this portal', 'guest_access_denied')
					);
					Application::getConnection()->unlock($lockName);

					return null;
				}

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
	 * The provider travels from the request into the stored call and into the pull event,
	 * so an unknown one is refused before anything is written. All three actions that take
	 * a provider answer with the same code and message.
	 */
	private function addInvalidProviderError(): void
	{
		$this->addError(new Error("Invalid provider", "invalid_provider"));
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
		// makeClone() keeps the inherited provider only for null, so any other value from
		// the request is written to the child call as is.
		if (!\Bitrix\Call\Call::isProviderSupported($newProvider))
		{
			$this->addInvalidProviderError();
			return null;
		}

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
		// An empty provider is rejected here, unlike Controller\Call::tryJoinCallAction: there the
		// provider arrives in a hydrated DTO defaulting to '', so '' means the field never arrived.
		// Here $provider is a required parameter with no default, so '' is a value the caller sent.
		if (!\Bitrix\Call\Call::isProviderSupported($provider))
		{
			$this->addInvalidProviderError();
			return null;
		}

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

		if (!(new GuestCallPolicy())->canInviteUsersNow((int)$currentUserId, $call->getChatId(), $userIds))
		{
			Application::getConnection()->unlock($lockName);
			$this->addError(new Error('Guests can only call members of their own chat', 'guest_access_denied'));

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

			// On a repeated invite skip participants that are already in a terminal
			// state (DECLINED / BUSY) — they must not receive another invitation
			// and must not be transitioned back to CALLING.  On the first
			// (non-repeated) invite this guard is intentionally absent per
			// invariant BHV-01.
			$callUser = $call->getUser($userId);
			if ($isRepeated && $callUser?->isTerminalState())
			{
				continue;
			}

			$usersToInvite[] = $userId;
			if ($callUser && $callUser->getState() !== CallUser::STATE_READY)
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
			if (!$isRepeated || !$call->areAllRequestedUsersTerminal($userIds))
			{
				// First invite always errors on an empty list. On a repeated invite an
				// empty list is an error too, unless every requested recipient is already
				// a participant in a terminal state (DECLINED / BUSY) — that is the only
				// legitimate "no one left to call" silent exit. Empty / extranet-filtered
				// input, invalid ids and add-user (capacity) failures are NOT terminal.
				$this->addError(new Error("No users to invite", "empty_users"));
			}
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
	 * Device-scoped ringing is a private 1-1 concern only. Group calls are created
	 * with TYPE_INSTANT too, so the call type is not a reliable 1-1 signal — the
	 * scope is the privacy of the associated chat. Mirrors Controller\Call.
	 */
	protected function isDeviceScopedCall(\Bitrix\Call\Call $call): bool
	{
		// Thin wrapper over the canonical domain predicate (kept as a test seam).
		return $call->isDeviceScopedRinging();
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

		// Pre-lock: one closed-form single-row read of the caller's row instead of
		// materializing the whole roster (parity with the JWT decline path).
		// Membership implies access, so reuse this CallUser for the state check
		// below; probe associated-entity access only when the caller is not a member.
		$callUser = $call->getUserClosedForm($currentUserId);
		if (!$callUser)
		{
			if (!$call->getAssociatedEntity()?->checkAccess($currentUserId))
			{
				$this->addError(new Error("You don't have access to the call " . $call->getId() . "; (current user id: " . $currentUserId . ")", 'access_denied'));
				return null;
			}

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

		// Device-aware decline for private 1-1 calls (parity with the JWT
		// transport): drop the declining device from the ringing registry and,
		// while the user still has other fresh ringing devices, stop here — the
		// CallUser stays CALLING, no hangup broadcast and no finish. Empty/unknown
		// registry (older clients) is a no-op -> legacy decline -> finish. Group
		// calls (TYPE_INSTANT is not a 1-1 signal) are decided by chat privacy.
		$callInstanceIdStr = (string)$callInstanceId;
		if ($this->isDeviceScopedCall($call) && $callInstanceIdStr !== '')
		{
			// The legacy transport releases $lockName manually at each return, so a
			// storage exception inside remove() must not leave the named lock held
			// until the DB session ends (blocking parallel decline/register).
			try
			{
				$hasFreshDevices = (new RingingDeviceRegistry())->remove($callId, $currentUserId, $callInstanceIdStr);
			}
			catch (\Throwable $exception)
			{
				Application::getConnection()->unlock($lockName);
				throw $exception;
			}

			if ($hasFreshDevices)
			{
				Application::getConnection()->unlock($lockName);
				return null;
			}
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

		if (!$call && !$callId && $callUuid)
		{
			// Quick hangup race: the call may not exist on the portal yet.
			// Re-check under the state lock; either the startCall webhook has
			// just created it (then finish it the regular way below), or mark
			// the room as finished so the late webhook does not revive it.
			$call = $this->getCallOrMarkRoomFinished($callUuid);
		}

		if (!$call)
		{
			if (empty($this->getErrors()))
			{
				$this->addError(new Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
			}
			return null;
		}

		$currentUserId = $this->getCurrentUser()->getId();
		if (!$call->checkAccessClosedForm($currentUserId))
		{
			$this->addError(new Error("You don't have access to the call " . $call->getId() . "; (current user id: " . $currentUserId . ")", 'access_denied'));
			return null;
		}

		if ($call->getState() === \Bitrix\Call\Call::STATE_FINISHED)
		{
			// Recovery re-broadcast for stuck clients that repeat finish on an
			// already-finished call, so a client still showing the call can clean up.
			$recoveryBroadcast = $call->setActionUserId($currentUserId)
				->getSignaling()
				->sendFinish(forRecovery: true)
			;

			if ($recoveryBroadcast)
			{
				Recent::scheduleUpdateCallCache($call->getId());
			}

			return [
				'call' => $call->toArray($currentUserId),
				'connectionData' => $call->getConnectionData($currentUserId),
				'logToken' => $call->getLogToken($currentUserId),
			];
		}

		// UUID-based lock to serialize with Controller\Call::answerAction and
		// Controller\Call::finishCallAction on the JWT scheme.
		$lockName = "call_state_call_{$call->getUuid()}";
		if (!Application::getConnection()->lock($lockName, static::LOCK_TTL))
		{
			$this->addError(new Error("Could not get exclusive lock", "could_not_lock"));
			return null;
		}

		$committedHere = false;
		$sendRecoveryFinish = false;
		$returnLiveState = false;
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
				// Already finished (lost race): only flag the recovery re-broadcast
				// here. The broadcast itself (getUsers() roster load + Pull fan-out)
				// is deferred to after unlock so it does not hold the shared
				// call_state lock and block concurrent answer/finish.
				$call->setActionUserId($currentUserId);
				$sendRecoveryFinish = true;
			}
			elseif ($silent && $this->hasActiveUsers($call))
			{
				// Advisory silent finish (stuck-call recovery): the call is still live,
				// so one stuck client must not tear it down for everyone. Skip the
				// FINISHED commit and its broadcast; the live call state is returned
				// after unlock (see below), so this hot, repeatable path holds the lock
				// only for the state read and the gate decision.
				$returnLiveState = true;
			}
			else
			{
				$call
					->setActionUserId($currentUserId)
					->setSilentFinish($silent)
				;
				// Only the atomic FINISHED transition is held under the lock.
				$committedHere = $call->commitFinish();
			}
		}
		finally
		{
			Application::getConnection()->unlock($lockName);
		}

		if ($returnLiveState)
		{
			// Advisory silent finish on a live call: build the response off-lock (the
			// live state was already read under the lock) and return without side
			// effects or Recent cache churn, since nothing changed. Building the
			// payload outside the critical section keeps this repeatable path from
			// holding the shared call_state lock while it renders connection data.
			return [
				'call' => $call->toArray($currentUserId),
				'connectionData' => $call->getConnectionData($currentUserId),
				'logToken' => $call->getLogToken($currentUserId),
			];
		}

		// Side effects run after unlock and only when this request performed the
		// transition (exactly-once). terminateAllCallsInChat now runs once via
		// EventHandler::onCallFinished, so it is no longer called directly here.
		if ($committedHere)
		{
			$call->finishSideEffects();
		}
		elseif ($sendRecoveryFinish && $call instanceof \Bitrix\Call\Call)
		{
			// Off-lock recovery re-broadcast for stuck clients still showing the
			// already-finished call. Pass $forRecovery=true to bypass the
			// suppression window; it is self-rate-limited by the recovery idempotence
			// key. Refresh Recent only for the request that actually broadcast (key
			// acquired), so repeated recovery finishes collapse to a single rebuild.
			if ($call->getSignaling()->sendFinish(forRecovery: true))
			{
				Recent::scheduleUpdateCallCache($call->getId());
			}
		}
		elseif ($call instanceof \Bitrix\Call\Call)
		{
			// Not committed here (lost race without recovery broadcast).
			Recent::scheduleUpdateCallCache($call->getId());
		}

		return [
			'call' => $call->toArray($currentUserId),
			'connectionData' => $call->getConnectionData($currentUserId),
			'logToken' => $call->getLogToken($currentUserId),
		];
	}

	/**
	 * @restMethod call.CallManager.getUsers
	 * @param int|null $callId
	 * @param int[] $userIds
	 * @param string|null $callUuid
	 * @return null|array
	 */
	public function getUsersAction(?int $callId = null, array $userIds = [], ?string $callUuid = null)
	{
		// $callUuid is intentionally the last parameter: siblings (finish/getUserState) put it
		// second, but here that would shift $userIds and break legacy {callId, userIds} callers.
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
	public function getUserStateAction(?int $callId = null, ?string $callUuid = null, int $userId = 0): ?array
	{
		$currentUserId = (int)$this->getCurrentUser()->getId();
		$call = $callId
			? Registry::getCallWithId($callId)
			: ($callUuid ? Registry::getCallWithUuid($callUuid) : null);

		// Closed-form single-user access: read the caller's row once and reuse it
		$currentCallUser = $call?->getUserClosedForm($currentUserId);
		if (!$call || (!$currentCallUser && !$call->getAssociatedEntity()?->checkAccess($currentUserId)))
		{
			if ($call)
			{
				$this->addError(new Error("You don't have access to the call " . $call->getId() . "; (current user id: " . $currentUserId . ")", 'access_denied'));
			}
			else
			{
				$this->addError(new Error("Call is not found or you do not have access to the call", "access_denied"));
			}
			return null;
		}

		if ($userId === 0 || $userId === $currentUserId)
		{
			$callUser = $currentCallUser;
		}
		else
		{
			$callUser = $call->getUserClosedForm($userId);
		}

		if (!$callUser)
		{
			$this->addError(new Error("User is not part of the call", "unknown_call_user"));
			return null;
		}

		return $callUser->toArray();
	}

	/**
	 * Returns bare existence and state of a call by uuid. Lets the web client
	 * tell a zombie media room (its call is already finished on the portal)
	 * from a legitimate join into an ongoing call.
	 *
	 * @restMethod call.CallManager.getCallState
	 * @param string|null $callUuid
	 * @return array|null
	 */
	public function getCallStateAction(?string $callUuid = null): ?array
	{
		if (!$callUuid || !preg_match(Call::UUID_V4_PATTERN, $callUuid))
		{
			$this->addError(new Error("Invalid call uuid", "invalid_call_uuid"));
			return null;
		}

		$call = Registry::getCallWithUuid($callUuid);
		if (!$call)
		{
			return ['exists' => false, 'state' => null];
		}

		if (!$this->checkCallAccess($call, (int)$this->getCurrentUser()->getId()))
		{
			$this->addError(new Error("Call is not found or you do not have access to the call", "access_denied"));
			return null;
		}

		return ['exists' => true, 'state' => $call->getState()];
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

	/**
	 * Re-reads the call by uuid under the call state lock. Returns the call
	 * when the startCall webhook managed to create it while we were waiting
	 * for the lock; otherwise marks the room as finished and returns null.
	 *
	 * @param string $callUuid
	 * @return \Bitrix\Call\Call|null
	 */
	private function getCallOrMarkRoomFinished(string $callUuid): ?\Bitrix\Call\Call
	{
		$lockName = "call_state_call_{$callUuid}";
		if (!Application::getConnection()->lock($lockName, static::LOCK_TTL))
		{
			// Report the lock explicitly so the client can retry the finish;
			// a silent call_not_found here would leave the room untouched.
			$this->addError(new Error("Could not get exclusive lock", "could_not_lock"));
			return null;
		}

		try
		{
			$call = Registry::getCallWithUuid($callUuid);
			if ($call)
			{
				return $call;
			}

			$this->markRoomFinished($callUuid, (int)$this->getCurrentUser()->getId());

			return null;
		}
		finally
		{
			Application::getConnection()->unlock($lockName);
		}
	}

	/**
	 * Sets the finished-room marker for the uuid and finishes the media room
	 * on the controller side. Used when a finish request arrives before the
	 * startCall webhook has created the call entity on the portal.
	 *
	 * @param string $callUuid
	 * @param int $userId
	 * @return void
	 */
	private function markRoomFinished(string $callUuid, int $userId): void
	{
		if (!preg_match(Call::UUID_V4_PATTERN, $callUuid))
		{
			return;
		}

		if (!Idempotence::addKey(Call::FINISHED_ROOM_MARKER_PREFIX . $callUuid, Call::FINISHED_ROOM_MARKER_TTL))
		{
			// The marker is already claimed: the room finish is scheduled by the
			// first caller, repeats must not multiply outbound HTTP requests.
			return;
		}

		// The marker must be set under the caller's lock, but the room-finish
		// HTTP goes out after the response, outside the lock (perf).
		Application::getInstance()->addBackgroundJob(
			static function () use ($callUuid, $userId) {
				$result = (new ControllerClient())->finishPlainByUuid($callUuid, $userId);
				if (!$result->isSuccess())
				{
					Logger::getInstance()->error(
						static::class . "::markRoomFinished: controller finishPlainByUuid failed for call uuid:{$callUuid}: "
						. implode('; ', $result->getErrorMessages()),
					);
				}
			},
		);
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
			// Open to a guest over REST, where the Csrf filter of the core does not run and the guest session
			// is recognised by its cookie alone (@see ActionFilter\SameOrigin). POST takes away the GET
			// navigation, Origin takes away the cross-site form; native clients send no Origin or a bare
			// portal host and pass.
			// The guest checks of this action are inside createAction: it also joins a running call, which
			// the portal switch does not govern.
			'create' => [
				'+prefilters' => [
					new Engine\ActionFilter\HttpMethod([Engine\ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\SameOrigin(),
				],
			],
			// Open to a guest over REST on the same terms as create above. POST is what takes away the
			// top-level GET navigation: a browser sends no Origin on one, so SameOrigin alone would let it
			// through (@see ActionFilter\SameOrigin).
			'invite' => [
				'+prefilters' => [
					new Engine\ActionFilter\HttpMethod([Engine\ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\SameOrigin(),
				],
			],
			// A guest never pulls anyone else into a call: stated at the action, because the guest allow-list
			// of the REST API does not cover ajax.php (@see ActionFilter\DenyGuest).
			'createChildCall' => [
				'+prefilters' => [new ActionFilter\DenyGuest()],
			],
		];
	}
}
