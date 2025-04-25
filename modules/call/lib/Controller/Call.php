<?php

namespace Bitrix\Call\Controller;

use Bitrix\Call\Idempotence;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Im\Call\CallUser;
use Bitrix\Im\Call\Integration\EntityType;
use Bitrix\Im\Call\Registry;
use Bitrix\Im\Call\Util;
use Bitrix\Im\V2\Call\CallFactory;
use Bitrix\Call\Error;
use Bitrix\Call\DTO;
use Bitrix\Call\JwtCall;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Controller\Filter\UniqueRequestFilter;

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
				function ($className, $params = [])
				{
					$parameters = $this->getSourceParametersList()[0];
					$userRequest = new DTO\UserRequest($parameters);
					return $userRequest;
				}
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
		$callToken = $tokenRequest->chatId ? JwtCall::getCallToken($tokenRequest->chatId, $tokenRequest->additionalData) : '';

		return [
			'callToken' => $callToken,
			'userToken' => JwtCall::getUserJwt(),
		];
	}

	/**
	 * @restMethod call.Call.startCall
	 *
	 * @param DTO\CallRequest $callRequest
	 * @return array|null
	 */
	public function startCallAction(DTO\CallRequest $callRequest): array|null
	{
		try
		{
			$tokenVersion = JwtCall::getTokenVersion($callRequest->chatId);

			if ($tokenVersion > $callRequest->tokenVersion)
			{
				$this->addError(new Error('Call token version deprecated',  'call_token_version_deprecated'));

				return [
					'result' => false,
					'errorCode' => 'call_token_version_deprecated',
					'errorMessage' => 'Call token version deprecated',
				];
			}

			Loader::includeModule('im');

			$userId = $callRequest->initiatorUserId;

			$call = CallFactory::createWithEntity(
				$callRequest->callType,
				$callRequest->provider,
				EntityType::CHAT,
				\Bitrix\Im\Dialog::getDialogId($callRequest->chatId, $userId),
				$userId,
				$callRequest->callUuid,
			);

			if ($call->hasErrors())
			{
				$this->addErrors($call->getErrors());
				return null;
			}

			$this->setUserStateReady($call, $userId, $callRequest->legacyMobile);

			$users = array_diff($call->getUsers(), [$userId]);
			$this->inviteUsers(
				$call,
				$users,
				$callRequest->video,
			);

			$callAIError = CallAISettings::isAIAvailableInCall();

			if ($callRequest->requestId)
			{
				Idempotence::addKey($callRequest->requestId);
			}

			return [
				'callId' => $call->getId(),
				'tokenVersion' => $tokenVersion,
				'autoStartAIRecording' => $call->autoStartRecording(),
				'AIAvailableInCall' => !$callAIError,
				'AIErrorCode' => $callAIError?->getCode(),
				'AIErrorMessage' => $callAIError?->getMessage(),
			];
		}
		catch (\Throwable $e)
		{
			$this->addError(new \Bitrix\Main\Error($e->getMessage(), $e->getCode()));
			return null;
		}
	}

	/**
	 * @restMethod call.Call.finishCall
	 *
	 * @param DTO\CallRequest $callRequest
	 * @return array|null
	 */
	public function finishCallAction(DTO\CallRequest $callRequest): array|null
	{
		Loader::includeModule('im');

		$call = Registry::getCallWithUuid($callRequest->callUuid);
		if (!$call)
		{
			$this->addError(new \Bitrix\Main\Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
			return null;
		}
		$userId = $callRequest->userId;
		if (!empty($userId) && !$call->checkAccess($userId))
		{
			$this->addError(new Error("You do not have access to the parent call", "access_denied"));
			return null;
		}

		if ($callRequest->isAudioRecord)
		{
			$call
				->setActionUserId($callRequest->audioRecordingInitiatorId)
				->enableAudioRecord()
				->enableAiAnalyze()
				->save()
			;
		}
		else
		{
			$call
				->setActionUserId($callRequest->audioRecordingInitiatorId)
				->disableAudioRecord()
				->disableAiAnalyze()
				->save()
			;
		}

		if (empty($userId))
		{
			$call->setActionUserId($call->getInitiatorId());
		}
		else
		{
			$call->setActionUserId($userId);
		}

		$call->finishCall();

		if ($callRequest->requestId)
		{
			Idempotence::addKey($callRequest->requestId);
		}

		return [
			'call' => $call->toArray($userId),
			'connectionData' => $call->getConnectionData($userId),
			'logToken' => $call->getLogToken($userId)
		];
	}

	protected function inviteUsers(
		\Bitrix\Im\Call\Call $call,
		$userIds,
		$isVideo = 'N',
		$isLegacyMobile = 'N',
		$isShow = 'Y',
		$isRepeated = 'N'
	): void
	{
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
			$this->addError(new \Bitrix\Main\Error("No users to invite", "empty_users"));
			return;
		}

		$sendPush = $isRepeated !== true;

		// send invite to the ones being invited.
		$call->sendInviteUsers(
			$this->getCurrentUser()->getId(),
			$usersToInvite,
			$isLegacyMobile,
			$isVideo,
			$sendPush
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

		if ($call->getState() === \Bitrix\Im\Call\Call::STATE_NEW)
		{
			$call->updateState(\Bitrix\Im\Call\Call::STATE_INVITING);
		}
	}

	/**
	 * @restMethod call.Call.answer
	 *
	 * @param DTO\UserRequest $userRequest
	 * @return void|null
	 */
	public function answerAction(DTO\UserRequest $userRequest)
	{
		Loader::includeModule('im');

		$isLegacyMobile = $userRequest->legacyMobile === 'Y';
		$call = Registry::getCallWithUuid($userRequest->callUuid);
		if (!$call)
		{
			$this->addError(new \Bitrix\Main\Error(Loc::getMessage('IM_REST_CALL_ERROR_CALL_NOT_FOUND'), 'call_not_found'));
			return null;
		}

		$currentUserId = $this->getCurrentUser()->getId();
		if (!$call->checkAccess($currentUserId))
		{
			return null;
		}

		$lockName = static::getLockNameWithCallId('user'.$currentUserId, $userRequest->callUuid);
		if (!Application::getConnection()->lock($lockName, static::LOCK_TTL))
		{
			$this->addError(new \Bitrix\Main\Error('Could not get exclusive lock', 'could_not_lock'));
			return null;
		}

		$this->setUserStateReady($call, $currentUserId, $isLegacyMobile);
		Application::getConnection()->unlock($lockName);

		$call->getSignaling()->sendAnswer($currentUserId, $userRequest->callInstanceId, $isLegacyMobile);
	}

	/**
	 * @restMethod call.Call.decline
	 *
	 * @param DTO\UserRequest $userRequest
	 * @return void|null
	 */
	public function declineAction(DTO\UserRequest $userRequest)
	{
		Loader::includeModule('im');

		$currentUserId = $this->getCurrentUser()->getId();

		$call = Registry::getCallWithUuid($userRequest->callUuid);

		if (!$call)
		{
			$this->addError(new \Bitrix\Main\Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
			return null;
		}

		if (!$call->checkAccess($currentUserId))
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

		$lockName = static::getLockNameWithCallId('user'.$currentUserId, $userRequest->callUuid);
		if (!Application::getConnection()->lock($lockName, static::LOCK_TTL))
		{
			$this->addError(new \Bitrix\Main\Error("Could not get exclusive lock", "could_not_lock"));
			return null;
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

		$userIds = $call->getUsers();
		$call->getSignaling()->sendHangup($currentUserId, $userIds, $userRequest->callInstanceId);

		if (!$call->hasActiveUsers())
		{
			$call->setActionUserId($currentUserId)->finish();
		}
	}

	/**
	 * @restMethod call.Call.userStatus
	 *
	 * @param DTO\CallUserRequest $callUserRequest
	 * @return void|null
	 */
	public function userStatusAction(DTO\CallUserRequest $callUserRequest)
	{
		$isLegacyMobile = $callUserRequest->legacyMobile === "Y";
		if (!$callUserRequest->callUuid)
		{
			$this->addError(new \Bitrix\Main\Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
			return null;
		}

		Loader::includeModule('im');

		$call = Registry::getCallWithUuid($callUserRequest->callUuid);
		if (!$call)
		{
			$this->addError(new \Bitrix\Main\Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
			return null;
		}

		if (!empty($callUserRequest->connectedUsers))
		{
			foreach ($callUserRequest->connectedUsers as $user)
			{
				if (!$call->checkAccess($user->userId))
				{
					continue;
				}

				$this->setUserStateReady($call, $user->userId, $isLegacyMobile);
			}

			$call->getSignaling()->sendConnectedUsers(
				$callUserRequest->connectedUsers,
				$isLegacyMobile
			);
		}

		if (!empty($callUserRequest->disconnectedUsers))
		{
			foreach ($callUserRequest->disconnectedUsers as $user)
			{
				$callUser = $call->getUser($user->userId);

				if (!$callUser)
				{
					continue;
				}

				$callUser->updateState(CallUser::STATE_IDLE);
				$callUser->updateLastSeen(new DateTime());
			}

			$call->getSignaling()->sendDisconnectedUsers(
				$callUserRequest->disconnectedUsers,
			);
		}
	}

	/**
	 * @restMethod call.Call.createChildCall
	 *
	 * @param DTO\CallRequest $callRequest
	 * @return array|null
	 */
	public function createChildCallAction(DTO\CallRequest $callRequest): ?array
	{
		Loader::includeModule('im');
		$parentCall = Registry::getCallWithUuid($callRequest->parentCallUuid);
		if (!$parentCall)
		{
			$this->addError(new Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
			return null;
		}

		$currentUserId = $callRequest->userId;
		if (!$parentCall->checkAccess($currentUserId))
		{
			$this->addError(new Error("You do not have access to the parent call", "access_denied"));
			return null;
		}

		$childCall = $parentCall->createChildCall(
			$callRequest->callUuid,
			\Bitrix\Im\Dialog::getDialogId($callRequest->chatId, $currentUserId),
			$callRequest->provider
		);
		if ($childCall->hasErrors())
		{
			$this->addErrors($childCall->getErrors());
			return null;
		}

		$this->setUserStateReady($childCall, $currentUserId, $callRequest->legacyMobile);

		$users = $childCall->getAssociatedEntity()->getUsers();

		$this->inviteUsers(
			$childCall,
			$users,
			$callRequest->video,
		);

		$callAIError = CallAISettings::isAIAvailableInCall();

		if ($callRequest->requestId)
		{
			Idempotence::addKey($callRequest->requestId);
		}

		return [
			'callId' => $childCall->getId(),
			'autoStartAIRecording' => $childCall->autoStartRecording(),
			'AIAvailableInCall' => !$callAIError,
			'AIErrorCode' => $callAIError?->getCode(),
			'AIErrorMessage' => $callAIError?->getMessage(),
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

		Loader::includeModule('im');

		$call = Registry::getCallWithUuid($userRequest->callUuid);
		if (!$call)
		{
			$this->addError(new \Bitrix\Main\Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
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

		$call->getUser($currentUserId)->update([
			'LAST_SEEN' => new DateTime(),
			'IS_MOBILE' => ($isLegacyMobile ? 'Y' : 'N')
		]);

		$lockName = static::getLockNameWithCallId('invite', $userRequest->callUuid);
		if (!Application::getConnection()->lock($lockName, static::LOCK_TTL))
		{
			$this->addError(new \Bitrix\Main\Error("Could not get exclusive lock", "could_not_lock"));
			return null;
		}

		$this->inviteUsers($call, $userIds, $isVideo, $isLegacyMobile, $isShow, $isRepeated);

		Application::getConnection()->unlock($lockName);
		return true;
	}

	/**
	 * @restMethod call.Call.createChatForChildCall
	 *
	 * @param DTO\UserRequest $userRequest
	 * @return array|null
	 */
	public function createChatForChildCallAction(DTO\UserRequest $userRequest): array|null
	{
		Loader::includeModule('im');
		$currentUserId = $this->getCurrentUser()->getId();

		$call = Registry::getCallWithUuid($userRequest->callUuid);
		if (!$call)
		{
			$this->addError(new \Bitrix\Main\Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
			return null;
		}

		$lockName = static::getLockNameWithCallId('user'.$currentUserId, $userRequest->callUuid);
		if (!Application::getConnection()->lock($lockName, static::LOCK_TTL))
		{
			$this->addError(new \Bitrix\Main\Error('Could not get exclusive lock', 'could_not_lock'));
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
			return ['result' => false];
		}

		$chat = $result->getResult()['CHAT'];
		$chatId = $chat->getChatId();
		if (!$chatId)
		{
			return ['result' => false];
		}
		$callToken = JwtCall::getCallToken($chatId, ['parentUuid' => $userRequest->callUuid]);

		Application::getConnection()->unlock($lockName);

		return [
			'result' => true,
			'token' => $callToken,
			'chatId' => $chatId,
		];
	}

	/**
	 * @param \Bitrix\Im\Call\Call $call
	 * @param int $userId
	 * @param bool $isLegacyMobile
	 */
	protected function setUserStateReady(\Bitrix\Im\Call\Call $call, int $userId, bool $isLegacyMobile): void
	{
		$callUser = $call->getUser($userId);
		if ($callUser)
		{
			$callUser->update([
				'STATE' => CallUser::STATE_READY,
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
		$call = Registry::getCallWithUuid($userRequest->callUuid);
		if (!$call)
		{
			$this->addError(new \Bitrix\Main\Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
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
		$call = Registry::getCallWithUuid($userRequest->callUuid);
		if (!$call)
		{
			$this->addError(new \Bitrix\Main\Error(Loc::getMessage("IM_REST_CALL_ERROR_CALL_NOT_FOUND"), "call_not_found"));
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
			$this->addError(new \Bitrix\Main\Error("You can not access this call", 'access_denied'));
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

	protected static function getLockNameWithCallId(string $prefix, string $callUuid): string
	{
		if (!empty($prefix) && !empty($callUuid))
		{
			return "{$prefix}_call_{$callUuid}";
		}

		return '';
	}

	/**
	 * @param \Bitrix\Im\Call\Call $call
	 * @param bool $isNew
	 * @return array{call: array, connectionData: array, users: array, userData: array, publicChannels: array, logToken: string, isNew: bool}
	 */
	protected function formatCallResponse(\Bitrix\Im\Call\Call $call, int $initiatorId = 0, bool $isNew = false): array
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
			'userData' => Util::getUsers($users),
			'publicChannels' => $publicChannels,
			'logToken' => $call->getLogToken($currentUserId),
			'callToken' => JwtCall::getCallToken($call->getChatId())
		];
		if ($isNew)
		{
			$response['isNew'] = $isNew;
		}

		return $response;
	}
}