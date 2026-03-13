<?php

namespace Bitrix\Call;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Integration\Chat;

/**
 * @internal
 */
class Signaling
{
	public const MODE_ALL = 'all';
	public const MODE_WEB = 'web';
	public const MODE_MOBILE = 'mobile';

	protected Call $call;

	public function __construct(Call $call)
	{
		$this->call = $call;
	}

	public function sendInviteToUser(int $senderId, int $toUserId, $invitedUsers, $isLegacyMobile, bool $video = false, bool $sendPush = true)
	{
		$users = $this->call->getUsers();

		$parentCall = $this->call->getParentId() ? Call::loadWithId($this->call->getParentId()) : null;
		$skipPush = $parentCall ?  $parentCall->getUsers() : [];
		$skipPush = array_flip($skipPush);

		$associatedEntity = $this->call->getAssociatedEntity();
		$isBroadcast = ($associatedEntity instanceof Chat) && $associatedEntity->isBroadcast();

		$config = [
			'call' => $this->call->toArray(($senderId == $toUserId ? $toUserId : 0)),
			'users' => $users,
			'invitedUsers' => $invitedUsers,
			'userData' => $this->call->getUserData(),
			'senderId' => $senderId,
			'publicIds' => $this->getPublicIds([$toUserId]),
			'isLegacyMobile' => $isLegacyMobile,
			'video' => $video,
			'logToken' => $this->call->getLogToken($toUserId),
		];
		$connectionData = $this->call->getConnectionData($toUserId);
		if ($connectionData !== null)
		{
			$config['connectionData'] = $connectionData;
		}

		$push = null;
		if (!isset($skipPush[$toUserId]) && $sendPush && !$isBroadcast)
		{
			$push = $this->getInvitePush($senderId, $toUserId, $isLegacyMobile, $video);
		}

		$this->send('Call::incoming', $toUserId, $config, $push);
	}

	public function sendInvite(int $senderId, array $toUserIds, $isLegacyMobile, bool $video = false, bool $sendPush = true)
	{
		$users = $this->call->getUsers();

		$parentCall = $this->call->getParentId() ? Call::loadWithId($this->call->getParentId()) : null;
		$skipPush = $parentCall ?  $parentCall->getUsers() : [];
		$skipPush = array_flip($skipPush);

		$associatedEntity = $this->call->getAssociatedEntity();
		$isBroadcast = ($associatedEntity instanceof Chat) && $associatedEntity->isBroadcast();

		foreach ($toUserIds as $toUserId)
		{
			$push = null;
			$config = [
				'call' => $this->call->toArray((count($toUserIds) == 1 ? $toUserId : 0)),
				'users' => $users,
				'invitedUsers' => $toUserIds,
				'userData' => $this->call->getUserData(),
				'senderId' => $senderId,
				'publicIds' => $this->getPublicIds($users),
				'isLegacyMobile' => $isLegacyMobile,
				'video' => $video,
				'logToken' => $this->call->getLogToken($toUserId),
			];
			$connectionData = $this->call->getConnectionData($toUserId);
			if ($connectionData !== null)
			{
				$config['connectionData'] = $connectionData;
			}
			if (!isset($skipPush[$toUserId]) && $sendPush && !$isBroadcast)
			{
				$push = $this->getInvitePush($senderId, $toUserId, $isLegacyMobile, $video);
			}

			$this->send('Call::incoming', $toUserId, $config, $push);
		}
	}

	protected function getInvitePush(int $senderId, int $toUserId, $isLegacyMobile, $video): array
	{
		$users = $this->call->getUsers();
		$associatedEntity = $this->call->getAssociatedEntity();
		$name = $associatedEntity ? $associatedEntity->getName($toUserId) : Loc::getMessage('IM_CALL_INVITE_NA');

		$email = null;
		$phone = null;
		if ($associatedEntity instanceof Chat)
		{
			if ($associatedEntity->isPrivateChat())
			{
				$userInstance = \Bitrix\Im\User::getInstance($senderId);
				$email = $userInstance->getEmail();
				$phone = $userInstance->getPhone();
				$phone = preg_replace("/[^0-9#*+,;]/", "", $phone);
			}
			$avatar = $associatedEntity->getAvatar($toUserId);
		}

		$pushText = Loc::getMessage('IM_CALL_INVITE', ['#USER_NAME#' => $name]);
		$pushTag = 'IM_CALL_'.$this->call->getId();
		$push = [
			'message' => $pushText,
			'expiry' => 0,
			'params' => [
				'ACTION' => 'IMINV_'.$this->call->getId()."_".time()."_".($video ? 'Y' : 'N'),
				'PARAMS' => [
					'type' => 'internal',
					'callerName' => htmlspecialcharsback($name),
					'callerAvatar' => $avatar ?? '',
					'call' => $this->call->toArray($toUserId),
					'video' => $video,
					'users' => $users,
					'isLegacyMobile' => $isLegacyMobile,
					'senderId' => $senderId,
					'senderEmail' => $email,
					'senderPhone' => $phone,
					'logToken' => $this->call->getLogToken($toUserId),
					'ts' => time(),
				]
			],
			'advanced_params' => [
				'id' => $pushTag,
				'notificationsToCancel' => [$pushTag],
				'androidHighPriority' => true,
				'useVibration' => true,
				'isVoip' => true,
				'callkit' => true,
			],
			'sound' => 'call.aif',
			'send_immediately' => 'Y',
		];

		$connectionData = $this->call->getConnectionData($toUserId);
		if ($connectionData !== null)
		{
			$push['params']['PARAMS']['connectionData'] = $connectionData;
		}

		return $push;
	}

	/**
	 * @param int $senderId
	 * @param int[] $joinedUsers
	 * @return bool
	 */
	public function sendUsersJoined(int $senderId, array $joinedUsers)
	{
		$config = [
			'call' => $this->call->toArray(),
			'users' => $joinedUsers,
			'userData' => $this->call->prepareUserData($joinedUsers),
			'senderId' => $senderId,
			'publicIds' => $this->getPublicIds($joinedUsers),
		];

		return $this->send('Call::usersJoined', $this->call->getUsers(), $config);
	}

	public function sendUsersInvited(int $senderId, array $toUserIds, array $users, bool $show)
	{
		$config = [
			'call' => $this->call->toArray(),
			'users' => $users,
			'userData' => $this->call->prepareUserData($users),
			'senderId' => $senderId,
			'publicIds' => $this->getPublicIds($users),
			'show' => $show,
		];

		return $this->send('Call::usersInvited', $toUserIds, $config);
	}

	public function sendAssociatedEntityReplaced(int $senderId)
	{
		$config = [
			'call' => $this->call->toArray(),
			'senderId' => $senderId,
		];

		$toUserIds = $this->call->getUsers();

		return $this->send('Call::associatedEntityReplaced', $toUserIds, $config);
	}

	public function sendAnswer(int $senderId, $callInstanceId, $isLegacyMobile)
	{
		$config = [
			'call' => $this->call->toArray(),
			'senderId' => $senderId,
			'callInstanceId' => $callInstanceId,
			'isLegacyMobile' => $isLegacyMobile,
		];

		$toUserIds = array_diff($this->call->getUsers(), [$senderId]);
		$this->send('Call::answer', $toUserIds, $config, null, 3600);

		$push = [
			'send_immediately' => 'Y',
			'expiry' => 0,
			'params' => [],
			'advanced_params' => [
				'id' => 'IM_CALL_'.$this->call->getId().'_ANSWER',
				'notificationsToCancel' => ['IM_CALL_'.$this->call->getId()],
				'isVoip' => true,
				'callkit' => true,
				'filterCallback' => [static::class, 'filterPushesForApple'],
			]
		];

		$this->send('Call::answer', $senderId, $config, $push, 3600);
	}

	public function sendPing(int $senderId, $requestId)
	{
		$config = [
			'requestId' => $requestId,
			'callId' => $this->call->getId(),
			'senderId' => $senderId
		];

		$toUserIds = $this->call->getUsers();
		$toUserIds = array_filter($toUserIds, function ($value) use ($senderId) {
			return $value != $senderId;
		});
		return $this->send('Call::ping', $toUserIds, $config, null, 0);
	}

	public function sendNegotiationNeeded(int $senderId, int $toUserId, $restart)
	{
		return $this->send('Call::negotiationNeeded', $toUserId, [
			'senderId' => $senderId,
			'restart' => $restart
		]);
	}

	public function sendConnectionOffer(int $senderId, int $toUserId, string $connectionId, string $offerSdp, string $userAgent)
	{
		return $this->send('Call::connectionOffer', $toUserId, [
			'senderId' => $senderId,
			'connectionId' => $connectionId,
			'sdp' => $offerSdp,
			'userAgent' => $userAgent
		]);
	}

	public function sendConnectionAnswer(int $senderId, int $toUserId, string $connectionId, string $answerSdp, string $userAgent)
	{
		return $this->send('Call::connectionAnswer', $toUserId, [
			'senderId' => $senderId,
			'connectionId' => $connectionId,
			'sdp' => $answerSdp,
			'userAgent' => $userAgent
		]);
	}

	public function sendIceCandidates(int $senderId, int $toUserId, string $connectionId, array $iceCandidates)
	{
		return $this->send('Call::iceCandidate', $toUserId, [
			'senderId' => $senderId,
			'connectionId' => $connectionId,
			'candidates' => $iceCandidates
		]);
	}

	public function sendHangup(int $senderId, array $toUserIds, ?string $callInstanceId, $code = 200)
	{
		$config = [
			'senderId' => $senderId,
			'callInstanceId' => $callInstanceId,
			'code' => $code,
		];

		$push = [
			'send_immediately' => 'Y',
			//'expiry' => 0,
			'params' => [],
			'advanced_params' => [
				'id' => 'IM_CALL_'.$this->call->getId().'_FINISH',
				'notificationsToCancel' => ['IM_CALL_'.$this->call->getId()],
				'callkit' => true,
				'filterCallback' => [static::class, 'filterPushesForApple'],
			]
		];

		return $this->send('Call::hangup', $toUserIds, $config, $push, 3600);
	}

	public function sendFinish()
	{
		return $this->sendFinishInternal($this->call->getUsers());
	}

	public function sendSwitchTrackRecordStatus(int $senderId, bool $isTrackRecordOn, string $errorCode = '')
	{
		$toUserIds = array_diff($this->call->getUsers(), [$senderId]);

		return $this->send('Call::switchTrackRecordStatus', $toUserIds, [
			'senderId' => $senderId,
			'isTrackRecordOn' => $isTrackRecordOn,
			'callUuid' => $this->call->getUuid(),
			'errorCode' => $errorCode,
		]);
	}

	public static function filterPushesForApple($message, $deviceType, $deviceToken)
	{
		if (!Loader::includeModule('pull'))
		{
			return false;
		}
		$result = !in_array(
			$deviceType,
			[
				\CPushDescription::TYPE_APPLE,
				\CPushDescription::TYPE_APPLE_VOIP,
			],
			true)
		;
		return $result;
	}

	protected function getPublicIds(array $userIds)
	{
		if (!Loader::includeModule('pull'))
		{
			return [];
		}

		return \Bitrix\Pull\Channel::getPublicIds([
			'USERS' => $userIds,
			'JSON' => true
		]);
	}

	protected function sendFinishInternal(array $users): bool
	{
		$push = [
			'send_immediately' => 'Y',
			//'expiry' => 0,
			'params' => [],
			'advanced_params' => [
				'id' => 'IM_CALL_'.$this->call->getId().'_FINISH',
				'notificationsToCancel' => ['IM_CALL_'.$this->call->getId()],
				'callkit' => true,
				'filterCallback' => [static::class, 'filterPushesForApple'],
			]
		];

		return $this->send('Call::finish', $users, [], $push, 3600);
	}

	public function sendLogToken(int $toUserId,): void
	{
		if (\Bitrix\Main\Loader::includeModule('pull'))
		{
			$uuid = $this->call->getUuid();

			$pushMessage = [
				'module_id' => 'call',
				'command' => 'Call::logTokenUpdate',
				'params' => [
					'uuid' => $uuid,
					'logToken' => $this->call->getLogToken($toUserId),
				],
			];

			\Bitrix\Pull\Event::add([$toUserId], $pushMessage);
		}
	}

	public function sendPushTokenUpdate(string $callToken, array $userIds): void
	{
		if (\Bitrix\Main\Loader::includeModule('pull'))
		{
			$chatId = (int)$this->call->getAssociatedEntity()?->getChatId();

			$pushMessage = [
				'module_id' => 'call',
				'command' => 'Call::callTokenUpdate',
				'params' => [
					'chatId' => $chatId,
					'dialogId' => 'chat'. $chatId,
					'callToken' => $callToken,
				],
				'extra' => \Bitrix\Im\Common::getPullExtra()
			];

			\Bitrix\Pull\Event::add($userIds, $pushMessage);
		}
	}

	public function sendCallInviteToUser(
		int $senderId,
		int $toUserId,
		$isLegacyMobile,
		bool $video = false,
		bool $sendPush = true,
		string $sendMode = self::MODE_ALL
	): void
	{
		$parentCall = $this->call->getParentId() ? Call::loadWithId($this->call->getParentId()) : null;
		$skipPush = $parentCall ?  $parentCall->getUsers() : [];
		$skipPush = array_flip($skipPush);

		$associatedEntity = $this->call->getAssociatedEntity();
		$isBroadcast = ($associatedEntity instanceof Chat) && $associatedEntity->isBroadcast();
		$chatId = (int)$this->call->getAssociatedEntity()?->getChatId();

		$config = [
			'callToken' => $chatId > 0 ? JwtCall::getCallToken($chatId) : '',
			'call' => $this->getCallInfoForSend(($senderId !== $toUserId ? $toUserId : 0)),
			'aiSettings' => $this->getCallAiSettings(),
			'isLegacyMobile' => $isLegacyMobile,
			'video' => $video,
			'logToken' => $this->call->getLogToken($toUserId),
		];

		$push = null;
		if (!isset($skipPush[$toUserId]) && $sendPush && !$isBroadcast)
		{
			$push = $this->getCallInvitePush($senderId, $toUserId, $isLegacyMobile, $video);
		}

		switch ($sendMode)
		{
			case self::MODE_WEB:
				$this->sendToWeb('Call::incoming', $toUserId, $config);
				break;
			case self::MODE_MOBILE:
				$this->sendToMobile($toUserId, $push);
				break;
			case self::MODE_ALL:
			default:
				$this->send('Call::incoming', $toUserId, $config, $push);
		}
	}

	protected function getCallInvitePush(int $senderId, int $toUserId, $isLegacyMobile, $video): array
	{
		$associatedEntity = $this->call->getAssociatedEntity();
		$name = $associatedEntity ? $associatedEntity->getName($toUserId) : Loc::getMessage('IM_CALL_INVITE_NA');

		$email = null;
		$phone = null;
		if ($associatedEntity instanceof Chat)
		{
			if ($associatedEntity->isPrivateChat())
			{
				$userInstance = \Bitrix\Im\User::getInstance($senderId);
				$email = $userInstance->getEmail();
				$phone = $userInstance->getPhone();
				$phone = preg_replace("/[^0-9#*+,;]/", "", $phone);
			}
			$avatar = $associatedEntity->getAvatar($toUserId);
		}

		$pushText = Loc::getMessage('IM_CALL_INVITE', ['#USER_NAME#' => $name]);
		$pushTag = 'IM_CALL_'.$this->call->getId();
		$chatId = (int)$this->call->getAssociatedEntity()?->getChatId();
		$push = [
			'message' => $pushText,
			'expiry' => 0,
			'params' => [
				'ACTION' => 'IMINV_'.$this->call->getId()."_".time()."_".($video ? 'Y' : 'N'),
				'PARAMS' => [
					'callToken' => $chatId > 0 ? JwtCall::getCallToken($chatId) : '',
					'call' => $this->getCallInfoForSend(($senderId === $toUserId ? $toUserId : 0)),
					'type' => 'internal',
					'callerName' => htmlspecialcharsback($name),
					'callerAvatar' => $avatar ?? '',
					'video' => $video,
					'isLegacyMobile' => $isLegacyMobile,
					'senderId' => $senderId,
					'senderEmail' => $email,
					'senderPhone' => $phone,
					'logToken' => $this->call->getLogToken($toUserId),
					'ts' => time(),
				]
			],
			'advanced_params' => [
				'id' => $pushTag,
				'notificationsToCancel' => [$pushTag],
				'androidHighPriority' => true,
				'useVibration' => true,
				'isVoip' => true,
				'callkit' => true,
			],
			'sound' => 'call.aif',
			'send_immediately' => 'Y',
		];

		return $push;
	}

	public function sendConnectedUsers(array $senders, bool $isLegacyMobile): void
	{
		foreach ($senders as $user)
		{
			$this->sendAnswer($user->userId, $user->callInstanceId, $isLegacyMobile);
		}
	}

	public function sendDisconnectedUsers(array $senders, $code = 200): void
	{
		foreach ($senders as $user)
		{
			$toUserIds =  $this->call->getUsers();
			$this->sendHangup($user->userId, $toUserIds, $user->callInstanceId, $code);
		}
	}

	public static function sendChangedCallV2Enable(bool $isJwtEnabled, ?bool $isPlainUseJwt = null, ?string $callBalancerUrl = null): void
	{
		if (Loader::includeModule('pull'))
		{
			if ($isPlainUseJwt === null)
			{
				$isPlainUseJwt = Settings::isPlainCallsUseNewScheme();
			}
			if ($callBalancerUrl === null)
			{
				$callBalancerUrl = Settings::getBalancerUrl();
			}

			\CPullStack::AddShared([
				'module_id' => 'call',
				'command' => 'Call::callV2AvailabilityChanged',
				'params' => [
					'isJwtEnabled' => $isJwtEnabled,
					'isPlainUseJwt' => $isPlainUseJwt,
					'callBalancerUrl' => $callBalancerUrl,
				],
			]);

			\Bitrix\Pull\Event::send();
		}
	}

	public static function sendClearCallTokens(): void
	{
		if (Loader::includeModule('pull'))
		{
			\CPullStack::AddShared([
				'module_id' => 'call',
				'command' => 'Call::clearCallTokens',
				'params' => [],
			]);

			\Bitrix\Pull\Event::send();
		}
	}

	public function sendFinishToInitiator(int $user): bool
	{
		return $this->sendFinishInternal([$user]);
	}

	protected function send(string $command, $users, array $params = [], $push = null, $ttl = 5): bool
	{
		if (!Loader::includeModule('pull'))
		{
			return false;
		}

		if (!isset($params['call']))
		{
			$params['call'] = [
				'ID' => $this->call->getId(),
				'UUID' => $this->call->getUuid(),
				'PROVIDER' => $this->call->getProvider(),
				'SCHEME' => $this->call->getScheme(),
			];
		}

		if (!isset($params['callId']))
		{
			$params['callId'] = $this->call->getId();
		}

		\Bitrix\Pull\Event::add($users, [
			'module_id' => 'call',
			'command' => $command,
			'params' => $params,
			'push' => $push,
			'expiry' => $ttl
		]);

		return true;
	}

	protected function sendToWeb(string $command, $users, array $params = [], $ttl = 5): bool
	{
		if (!Loader::includeModule('pull'))
		{
			return false;
		}

		if (!isset($params['call']))
		{
			$params['call'] = [
				'ID' => $this->call->getId(),
				'UUID' => $this->call->getUuid(),
				'PROVIDER' => $this->call->getProvider(),
				'SCHEME' => $this->call->getScheme(),
			];
		}

		if (!isset($params['callId']))
		{
			$params['callId'] = $this->call->getId();
		}

		\Bitrix\Pull\Event::add($users, [
			'module_id' => 'call',
			'command' => $command,
			'params' => $params,
			'expiry' => $ttl
		]);

		return true;
	}

	protected function sendToMobile($users, $push = null, $ttl = 5): bool
	{
		if (!Loader::includeModule('pull'))
		{
			return false;
		}

		\Bitrix\Pull\Push::add($users, [
			'module_id' => 'call',
			'push' => $push,
			'expiry' => $ttl
		]);

		return true;
	}

	protected function getCallAiSettings(): array
	{
		return [
			'serviceEnabled' => Settings::isAIServiceEnabled(),
			'settingsEnabled' => CallAISettings::isEnableBySettings(),
			'recordingMinUsers' => CallAISettings::getRecordMinUsers(),
			'agreementAccepted' => CallAISettings::isAgreementAccepted(),
			'tariffAvailable' => CallAISettings::isTariffAvailable(),
			'baasAvailable' => CallAISettings::baasAvailable(),
			'marketSubscriptionEnabled' => CallAISettings::isMarketSubscriptionEnabled(),
		];
	}

	protected function getCallInfoForSend($userId): array
	{
		return [
			'uuid' => $this->call->getUuid(),
			'parentId' => $this->call->getParentId(),
			'parentUuid' => $this->call->getParentUuid(),
			'provider' => $this->call->getProvider(),
			'type' => $this->call->getType(),
			'initiatorId' => $this->call->getInitiatorId(),
			'startDate' => $this->call->getStartDate(),
			'associatedEntity' => $this->call->getAssociatedEntity()->toArray($userId),
			'userCounter' => count($this->call->getUsers()),
			'scheme' => $this->call->getScheme(),
		];
	}
}
