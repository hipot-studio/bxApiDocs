<?php

namespace Bitrix\Call;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Integration\Chat;
use Bitrix\Pull;

/**
 * @internal
 */
class Signaling
{
	public const MODE_ALL = 'all';
	public const MODE_WEB = 'web';
	public const MODE_MOBILE = 'mobile';

	private const LARGE_CHAT_NOTIFICATION_THRESHOLD = 20; /** @var Call @see call/install/js/call/core/src/controller.js:110 */

	/**
	 * TTL for the recovery-broadcast suppression key. On a large call after the
	 * original Call::finish, hundreds of stuck clients may trigger CallManager.finish
	 */
	private const FINISH_RECOVERY_TTL = 60;

	protected Call $call;

	private ?array $inviteContext = null;

	private array $callInfoCache = [];

	public function __construct(Call $call)
	{
		$this->call = $call;
	}

	public function sendInviteToUser(int $senderId, int $toUserId, $invitedUsers, $isLegacyMobile, bool $video = false, bool $sendPush = true, bool $isRepeated = false)
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
			'userData' => $this->call->getUserData($invitedUsers),
			'senderId' => $senderId,
			'publicIds' => $this->getPublicIds([$toUserId]),
			'isLegacyMobile' => $isLegacyMobile,
			'video' => $video,
			'logToken' => $this->call->getLogToken($toUserId),
			'isRepeated' => $isRepeated,
		];
		$connectionData = $this->call->getConnectionData($toUserId);
		if ($connectionData !== null)
		{
			$config['connectionData'] = $connectionData;
		}

		// Skip mobile invite entirely for users already in another call
		$flags = $this->resolveActiveCallFlags($parentCall, $toUserId, null);
		$isAlreadyInCall = $flags['isAlreadyInCall'];

		$push = null;
		if (!isset($skipPush[$toUserId]) && $sendPush && !$isBroadcast && !$isAlreadyInCall)
		{
			$push = $this->getInvitePush($senderId, $toUserId, $isLegacyMobile, $video);
		}

		$this->send('Call::incoming', $toUserId, $config, $push);
	}

	/**
	 * @param int[]|null $usersInOtherCalls
	 */
	public function sendInvite(int $senderId, array $toUserIds, $isLegacyMobile, bool $video = false, bool $sendPush = true, bool $isRepeated = false, ?array $usersInOtherCalls = null)
	{
		$toUserIds = array_values(array_unique(array_map('intval', $toUserIds)));
		if ($toUserIds === [])
		{
			return;
		}

		if (!Loader::includeModule('pull'))
		{
			return;
		}

		$users = $this->call->getUsers();

		$parentCall = $this->call->getParentId() ? Call::loadWithId($this->call->getParentId()) : null;
		$skipPush = $parentCall ?  $parentCall->getUsers() : [];
		$skipPush = array_flip($skipPush);

		$associatedEntity = $this->call->getAssociatedEntity();
		$isBroadcast = ($associatedEntity instanceof Chat) && $associatedEntity->isBroadcast();

		// Single recipient keeps per-user `call` payload (1:1 chats swap name/avatar).
		// Multi-recipient batch falls back to userId=0, matching prior behavior where
		// the per-user variant was only emitted when count($toUserIds) === 1.
		$callPayload = $this->call->toArray(count($toUserIds) === 1 ? $toUserIds[0] : 0);

		$sharedParams = [
			'call' => $callPayload,
			'users' => $users,
			'invitedUsers' => $toUserIds,
			'userData' => $this->call->getUserData($toUserIds),
			'senderId' => $senderId,
			'publicIds' => $this->getPublicIds($users),
			'isLegacyMobile' => $isLegacyMobile,
			'video' => $video,
			'callId' => $this->call->getId(),
		];

		// Per-recipient overrides merged by pull JSON-RPC server before delivery.
		// Keys must be userId — channel-id keys would silently drop the publish.
		$userParams = [];
		foreach ($toUserIds as $toUserId)
		{
			$perUser = [
				'logToken' => $this->call->getLogToken($toUserId),
				'isRepeated' => $isRepeated,
			];
			$connectionData = $this->call->getConnectionData($toUserId);
			if ($connectionData !== null)
			{
				$perUser['connectionData'] = $connectionData;
			}
			$userParams[$toUserId] = $perUser;
		}

		Pull\Event::add($toUserIds, [
			'module_id' => 'call',
			'command' => 'Call::incoming',
			'params' => $sharedParams,
			'user_params' => $userParams,
			'expiry' => 5,
		]);

		// Mobile push stays per-user — getInvitePush swaps name/avatar/email/phone
		// per recipient for 1:1 chats and embeds a per-user logToken.
		if ($sendPush && !$isBroadcast)
		{
			$usersBusy = $usersInOtherCalls !== null ? array_flip($usersInOtherCalls) : null;
			foreach ($toUserIds as $toUserId)
			{
				if (isset($skipPush[$toUserId]))
				{
					continue;
				}
				// Skip mobile invite entirely for users already in another call
				$flags = $this->resolveActiveCallFlags($parentCall, $toUserId, $usersBusy);
				if ($flags['isAlreadyInCall'])
				{
					continue;
				}
				$push = $this->getInvitePush($senderId, $toUserId, $isLegacyMobile, $video);
				$this->sendToMobile($toUserId, $push);
			}
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
			'userData' => $this->call->getUserData([$senderId]),
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

	/**
	 * Decline-specific counterpart of {@see self::sendHangup()}. Sends Call::hangup
	 * only to the recipient set required for UI correctness:
	 *   - the call initiator (sees who declined),
	 *   - the sender (multi-instance feedback through callInstanceId),
	 *   - currently-active peers (STATE_READY) — needed for the frontend's
	 *     auto-hangup check `if (!isAnyoneParticipating()) hangup()` and to
	 *     update the declining peer tile inside an active call view.
	 */
	public function sendHangupForDecline(int $senderId, ?string $callInstanceId, int $code = 603): bool
	{
		$recipients = [$this->call->getInitiatorId(), $senderId];
		$narrowFanOut = $this->call->getUserCount() > self::LARGE_CHAT_NOTIFICATION_THRESHOLD;
		foreach ($this->call->getCallUsers() as $callUser)
		{
			if ($narrowFanOut && $callUser->getState() !== CallUser::STATE_READY)
			{
				continue;
			}
			$recipients[] = $callUser->getUserId();
		}
		$recipients = array_values(array_unique($recipients));
		if ($recipients === [])
		{
			return true;
		}

		return (bool)$this->sendHangup($senderId, $recipients, $callInstanceId, $code);
	}

	/**
	 * @param bool $forRecovery When true, bypasses the FINISH_BROADCAST_TTL
	 *        suppression to re-broadcast for clients that missed the original
	 *        finish. Recovery has its own rate-limit (FINISH_RECOVERY_TTL) so
	 *        a flood of CallManager.finish recovery requests from many stuck
	 *        clients collapses to a single broadcast.
	 */
	public function sendFinish(bool $forRecovery = false)
	{
		if ($forRecovery)
		{
			$recoveryKey = 'call_finish_recovery_' . $this->call->getId();
			if (!Idempotence::addKey($recoveryKey, self::FINISH_RECOVERY_TTL))
			{
				return false;
			}

			// Release the recovery key on push failure so the next stuck-client
			// recovery attempt within the TTL window gets another chance to
			// broadcast — otherwise late clients would silently be denied the
			// finish-event for up to FINISH_RECOVERY_TTL seconds.
			$result = $this->sendFinishInternal($this->call->getUsers(), true, $forRecovery);
			if (!$result)
			{
				Idempotence::clearKey($recoveryKey);
			}

			return $result;
		}

		return $this->sendFinishInternal($this->call->getUsers(), true, $forRecovery);
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

	/**
	 * TTL for the broadcast-suppression idempotence key. Repeated broadcast
	 * attempts for the same callId within this window collapse to no-ops.
	 */
	private const FINISH_BROADCAST_TTL = 10;

	protected function sendFinishInternal(array $users, bool $isBroadcast = true, bool $skipSuppression = false): bool
	{
		if ($isBroadcast && !$skipSuppression)
		{
			$key = 'call_finish_broadcast_' . $this->call->getId();
			if (!Idempotence::addKey($key, self::FINISH_BROADCAST_TTL))
			{
				// Already broadcast within the suppression window — drop the fan-out.
				return false;
			}
		}

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

			Pull\Event::add([$toUserId], $pushMessage);
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

			Pull\Event::add($userIds, $pushMessage);
		}
	}

	public function buildInviteContext(int $senderId): self
	{
		$parentId = $this->call->getParentId();
		$parentCall = $parentId ? Call::loadWithId($parentId) : null;

		$associatedEntity = $this->call->getAssociatedEntity();
		$isBroadcast = ($associatedEntity instanceof Chat) && $associatedEntity->isBroadcast();
		$chatId = (int)$associatedEntity?->getChatId();
		$callToken = $chatId > 0 ? JwtCall::getCallToken($chatId) : '';

		// For group chats, $associatedEntity->toArray($userId) returns the same
		// data for every user — we can compute it once. Private 1:1 chats swap
		// name/avatar per receiver, so fall back to per-user computation there.
		$isGroupEntity = ($associatedEntity instanceof Chat) && !$associatedEntity->isPrivateChat();
		$callInfoShared = $isGroupEntity ? $this->getCallInfoForSend(0) : null;

		$senderEmail = null;
		$senderPhone = null;
		if (($associatedEntity instanceof Chat) && $associatedEntity->isPrivateChat())
		{
			$userInstance = \Bitrix\Im\User::getInstance($senderId);
			$senderEmail = $userInstance->getEmail();
			$senderPhone = preg_replace("/[^0-9#*+,;]/", "", (string)$userInstance->getPhone());
		}

		$this->inviteContext = [
			'parentCall' => $parentCall,
			'skipPush' => $parentCall ? array_flip($parentCall->getUsers()) : [],
			'isBroadcast' => $isBroadcast,
			'chatId' => $chatId,
			'callToken' => $callToken,
			'aiSettings' => $this->getCallAiSettings(),
			'callInfoShared' => $callInfoShared,
			'senderEmail' => $senderEmail,
			'senderPhone' => $senderPhone,
		];

		return $this;
	}

	public function resetInviteContext(): self
	{
		$this->inviteContext = null;
		$this->callInfoCache = [];
		return $this;
	}

	/**
	 * Batched counterpart of {@see self::sendCallInviteToUser()}.
	 *
	 * Emits a single `Pull\Event::add($toUserIds, ['user_params' => [...]])` for
	 * the web channel (MODE_WEB / MODE_ALL) — the call-module dispatches one
	 * event instead of N, and `user_params` carries per-recipient overrides
	 * (logToken, isAlreadyInCall, activeCallPlatform; plus callInfo for 1:1).
	 *
	 * Mobile push (MODE_MOBILE / MODE_ALL when not broadcast) stays per-user —
	 * each recipient needs a distinct push payload (name/avatar swap for 1:1),
	 * and batched push requires pull-side pushParamsCallback wiring.
	 *
	 * @param int[] $toUserIds
	 * @param int[] $usersInOtherCalls
	 */
	public function sendCallInviteBatch(
		int $senderId,
		array $toUserIds,
		$isLegacyMobile,
		bool $video = false,
		bool $sendPush = true,
		string $sendMode = self::MODE_ALL,
		array $usersInOtherCalls = [],
		bool $isRepeated = false,
	): void
	{
		$toUserIds = array_values(array_unique(array_map('intval', $toUserIds)));
		if ($toUserIds === [])
		{
			return;
		}

		$this->buildInviteContext($senderId);
		try
		{
			$ctx = $this->inviteContext;
			$parentCall = $ctx['parentCall'];
			$skipPush = $ctx['skipPush'];
			$isBroadcast = (bool)$ctx['isBroadcast'];
			$callToken = (string)$ctx['callToken'];
			$aiSettings = $ctx['aiSettings'];
			$callInfoShared = $ctx['callInfoShared'];
			$usersBusy = array_flip($usersInOtherCalls);

			$sendWeb = ($sendMode === self::MODE_WEB || $sendMode === self::MODE_ALL);
			$sendMobile = ($sendMode === self::MODE_MOBILE || $sendMode === self::MODE_ALL)
				&& $sendPush
				&& !$isBroadcast;

			if ($sendWeb && Loader::includeModule('pull'))
			{
				// Recipients and user_params are both keyed by userId. Channel-id
				// pre-resolution looks tempting (saves CPullChannel::Get inside
				// pull's getChannelIds), but the JSON-RPC push-server drops any
				// publish whose recipient list and/or user_params keys are 32-char
				// channel strings — only userId-keyed user_params are merged on
				// its side. Keeping userId keys here trades the per-user channel
				// SELECT back for delivery correctness across all transports.
				$userParams = [];
				$recipients = [];
				foreach ($toUserIds as $toUserId)
				{
					$flags = $this->resolveActiveCallFlags($parentCall, $toUserId, $usersBusy);
					$perUser = [
						'logToken' => $this->call->getLogToken($toUserId),
						'isAlreadyInCall' => $flags['isAlreadyInCall'],
						'activeCallPlatform' => $flags['activeCallPlatform'],
					];
					if ($callInfoShared === null)
					{
						// Private 1:1 — callInfo swaps name/avatar per receiver.
						$perUser['call'] = $this->getCallInfoForSend($senderId !== $toUserId ? $toUserId : 0);
					}
					$recipients[] = $toUserId;
					$userParams[$toUserId] = $perUser;
				}

				$sharedParams = [
					'callToken' => $callToken,
					'call' => $callInfoShared ?? $this->getCallInfoForSend(0),
					'aiSettings' => $aiSettings,
					'isLegacyMobile' => $isLegacyMobile,
					'video' => $video,
					'callId' => $this->call->getId(),
					'isRepeated' => $isRepeated,
				];

				Pull\Event::add($recipients, [
					'module_id' => 'call',
					'command' => 'Call::incoming',
					'params' => $sharedParams,
					'user_params' => $userParams,
					'expiry' => 5,
				]);
			}

			if ($sendMobile)
			{
				foreach ($toUserIds as $toUserId)
				{
					if (isset($skipPush[$toUserId]))
					{
						continue;
					}
					$flags = $this->resolveActiveCallFlags($parentCall, $toUserId, $usersBusy);
					if ($flags['isAlreadyInCall'])
					{
						// User is busy in another call — skip mobile invite entirely.
						// Silencing flags (callkit/isVoip/sound) only mute the OS-level
						// notification on iOS and don't stop the native call screen on either platform.
						continue;
					}
					$push = $this->getCallInvitePush($senderId, $toUserId, $isLegacyMobile, $video);
					$this->sendToMobile($toUserId, $push);
				}
			}
		}
		finally
		{
			$this->resetInviteContext();
		}
	}

	/**
	 * Recipient set for aggregated `Call::usersAnswered` / `Call::usersHangup` broadcasts.
	 *
	 * In small chats (≤ {@see self::LARGE_CHAT_NOTIFICATION_THRESHOLD})
	 * returns the full roster — preserves legacy `sendAnswer` / `sendHangup`
	 * fan-out and avoids UI regressions on edge transitions.
	 * In large chats narrows to peers whose current state is in `$filterUserStates`,
	 * plus anyone listed in `$alwaysInclude` regardless of their current state.
	 *
	 * @param string[] $filterUserStates
	 *        Whitelist of {@see CallUser}::STATE_* string values used for large-chat
	 *        fan-out. Members are matched against {@see CallUser::getState()} as
	 *        plain values (e.g. `[CallUser::STATE_READY, CallUser::STATE_CALLING]`).
	 *        The list is internally flipped to a state→position map for O(1)
	 *        `isset()` lookup; do NOT pre-flip it at the call site.
	 *        An empty array filters out every user — supply `$alwaysInclude` if you
	 *        need a non-empty result.
	 * @param int[] $alwaysInclude
	 *        Optional userId list that is appended to the result without state check
	 *        (e.g. event sender for self-cancel CallKit push, or hung-up users that
	 *        already left the room but still need the broadcast). `<=0` entries are
	 *        skipped; the result is NOT deduplicated, so callers passing both an
	 *        already-matching userId and `$alwaysInclude` may receive that userId twice.
	 * @return int[] Plain list of userIds, in iteration order: state-matched users
	 *         first, then `$alwaysInclude` tail.
	 */
	private function filterFanOutRecipients(array $filterUserStates, array $alwaysInclude = []): array
	{
		if ($this->call->getUserCount() <= self::LARGE_CHAT_NOTIFICATION_THRESHOLD)
		{
			return $this->call->getUsers();
		}

		$allowedStates = array_flip($filterUserStates);
		$recipients = [];
		foreach ($this->call->getCallUsers() as $callUser)
		{
			if (isset($allowedStates[$callUser->getState()]))
			{
				$recipients[] = $callUser->getUserId();
			}
		}
		foreach ($alwaysInclude as $uid)
		{
			$uid = (int)$uid;
			if ($uid)
			{
				$recipients[] = $uid;
			}
		}

		return $recipients;
	}

	/**
	 * Resolves the (isAlreadyInCall, activeCallPlatform) pair for a recipient.
	 * Shared between {@see self::sendCallInviteToUser()} and {@see self::sendCallInviteBatch()} to keep the semantics identical.
	 *
	 * @return array{isAlreadyInCall: bool, activeCallPlatform: ?string}
	 */
	private function resolveActiveCallFlags(?Call $parentCall, int $toUserId, ?array $usersInOtherCalls): array
	{
		$isAlreadyInCall = false;
		$activeCallPlatform = null;

		$parentCallUser = $parentCall?->getUser($toUserId);
		if ($parentCallUser?->getState() === CallUser::STATE_READY)
		{
			$isAlreadyInCall = true;
			$activeCallPlatform = $parentCallUser->isUaMobile() ? 'mobile' : 'web';
		}
		elseif ($usersInOtherCalls !== null)
		{
			if (isset($usersInOtherCalls[$toUserId]))
			{
				$isAlreadyInCall = true;
				$activeCallPlatform = 'any';
			}
		}
		elseif (CallFactory::isUserInActiveCall($toUserId, $this->call->getId()))
		{
			$isAlreadyInCall = true;
			$activeCallPlatform = 'any';
		}

		return [
			'isAlreadyInCall' => $isAlreadyInCall,
			'activeCallPlatform' => $activeCallPlatform,
		];
	}

	public function sendCallInviteToUser(
		int $senderId,
		int $toUserId,
		$isLegacyMobile,
		bool $video = false,
		bool $sendPush = true,
		string $sendMode = self::MODE_ALL,
		?array $usersInOtherCalls = null,
	): void
	{
		if ($this->inviteContext !== null)
		{
			$parentCall = $this->inviteContext['parentCall'] ?? null;
			$skipPush = $this->inviteContext['skipPush'] ?? [];
			$isBroadcast = (bool)($this->inviteContext['isBroadcast'] ?? false);
			$callToken = (string)($this->inviteContext['callToken'] ?? '');
			$aiSettings = $this->inviteContext['aiSettings'] ?? $this->getCallAiSettings();
			$callInfoShared = $this->inviteContext['callInfoShared'] ?? null;
		}
		else
		{
			$parentCall = $this->call->getParentId() ? Call::loadWithId($this->call->getParentId()) : null;
			$skipPush = $parentCall ? array_flip($parentCall->getUsers()) : [];
			$associatedEntity = $this->call->getAssociatedEntity();
			$isBroadcast = ($associatedEntity instanceof Chat) && $associatedEntity->isBroadcast();
			$chatId = (int)$associatedEntity?->getChatId();
			$callToken = $chatId > 0 ? JwtCall::getCallToken($chatId) : '';
			$aiSettings = $this->getCallAiSettings();
			$callInfoShared = null;
		}

		$flags = $this->resolveActiveCallFlags(
			$parentCall,
			$toUserId,
			$usersInOtherCalls !== null ? array_flip($usersInOtherCalls) : null,
		);
		$isAlreadyInCall = $flags['isAlreadyInCall'];
		$activeCallPlatform = $flags['activeCallPlatform'];

		$config = [
			'callToken' => $callToken,
			'call' => $callInfoShared ?? $this->getCallInfoForSend(($senderId !== $toUserId ? $toUserId : 0)),
			'aiSettings' => $aiSettings,
			'isLegacyMobile' => $isLegacyMobile,
			'video' => $video,
			'logToken' => $this->call->getLogToken($toUserId),
			'isAlreadyInCall' => $isAlreadyInCall,
			'activeCallPlatform' => $activeCallPlatform,
		];

		$push = null;
		// Skip mobile invite entirely for users already in another call:
		// silencing flags don't stop CallKit on iOS or callservice on Android,
		// so the native incoming screen would still grab audio from the
		// active call. Web-pull stays — busy device declines via controller.
		if (!isset($skipPush[$toUserId]) && $sendPush && !$isBroadcast && !$isAlreadyInCall)
		{
			$push = $this->getCallInvitePush($senderId, $toUserId, $isLegacyMobile, $video);
		}

		switch ($sendMode)
		{
			case self::MODE_WEB:
				$this->sendToWeb('Call::incoming', $toUserId, $config);
				break;
			case self::MODE_MOBILE:
				if ($push !== null)
				{
					$this->sendToMobile($toUserId, $push);
				}
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

		if ($this->inviteContext !== null)
		{
			$email = $this->inviteContext['senderEmail'] ?? null;
			$phone = $this->inviteContext['senderPhone'] ?? null;
			$callToken = (string)($this->inviteContext['callToken'] ?? '');
			$callInfoShared = $this->inviteContext['callInfoShared'] ?? null;
		}
		else
		{
			$email = null;
			$phone = null;
			if ($associatedEntity instanceof Chat)
			{
				if ($associatedEntity->isPrivateChat())
				{
					$userInstance = \Bitrix\Im\User::getInstance($senderId);
					$email = $userInstance->getEmail();
					$phone = preg_replace("/[^0-9#*+,;]/", "", (string)$userInstance->getPhone());
				}
			}
			$chatId = (int)$associatedEntity?->getChatId();
			$callToken = $chatId > 0 ? JwtCall::getCallToken($chatId) : '';
			$callInfoShared = null;
		}

		$avatar = $associatedEntity instanceof Chat ? $associatedEntity->getAvatar($toUserId) : null;

		$pushText = Loc::getMessage('IM_CALL_INVITE', ['#USER_NAME#' => $name]);
		$pushTag = 'IM_CALL_'.$this->call->getId();
		$push = [
			'message' => $pushText,
			'expiry' => 0,
			'params' => [
				'ACTION' => 'IMINV_'.$this->call->getId()."_".time()."_".($video ? 'Y' : 'N'),
				'PARAMS' => [
					'callToken' => $callToken,
					'call' => $callInfoShared ?? $this->getCallInfoForSend(($senderId === $toUserId ? $toUserId : 0)),
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

	/**
	 * Aggregated counterpart of {@see self::sendConnectedUsers()}: a single
	 * `Pull\Event::add($allCallUsers, ['command' => 'Call::usersAnswered',
	 * 'params' => ['senders' => [...]]])` for the web channel — replaces
	 * N × `sendAnswer` (each of which fires its own `Pull\Event::add` and
	 * triggers per-user `CPullChannel::Get` lookups inside pull's `fillChannels`).
	 *
	 * @param array $connectedUsers list of objects with `userId` and `callInstanceId` (matches `DTO\CallUserRequest::connectedUsers`)
	 */
	public function sendUsersAnswered(array $connectedUsers, bool $isLegacyMobile): bool
	{
		if ($connectedUsers === [])
		{
			return true;
		}

		$payload = [];
		foreach ($connectedUsers as $user)
		{
			$senderId = (int)($user->userId ?? 0);
			if ($senderId <= 0)
			{
				continue;
			}
			$payload[] = [
				'senderId' => $senderId,
				'callInstanceId' => $user->callInstanceId ?? null,
				'isLegacyMobile' => $isLegacyMobile,
			];
		}
		if ($payload === [])
		{
			return true;
		}

		if (!Loader::includeModule('pull'))
		{
			return false;
		}

		$callUsers = $this->filterFanOutRecipients([CallUser::STATE_READY, CallUser::STATE_CALLING]);
		if ($callUsers === [])
		{
			return true;
		}

		Pull\Event::add($callUsers, [
			'module_id' => 'call',
			'command' => 'Call::usersAnswered',
			'params' => [
				'call' => [
					'ID' => $this->call->getId(),
					'UUID' => $this->call->getUuid(),
					'PROVIDER' => $this->call->getProvider(),
					'SCHEME' => $this->call->getScheme(),
				],
				'callId' => $this->call->getId(),
				'senders' => $payload,
			],
			'expiry' => 3600,
		]);

		// Self-cancel CallKit push for each sender (multi-device sync).
		// Bounded by count($senders), not by call size.
		$selfPushTemplate = [
			'send_immediately' => 'Y',
			'expiry' => 0,
			'params' => [],
			'advanced_params' => [
				'id' => 'IM_CALL_'.$this->call->getId().'_ANSWER',
				'notificationsToCancel' => ['IM_CALL_'.$this->call->getId()],
				'isVoip' => true,
				'callkit' => true,
				'filterCallback' => [static::class, 'filterPushesForApple'],
			],
		];
		foreach ($payload as $sender)
		{
			Pull\Push::add([$sender['senderId']], [
				'module_id' => 'call',
				'push' => $selfPushTemplate,
				'expiry' => 3600,
			]);
		}

		return true;
	}

	/**
	 * Aggregated counterpart of {@see self::sendDisconnectedUsers()}: a single
	 * `Pull\Event::add($allCallUsers, ['command' => 'Call::usersHangup',
	 * 'params' => ['users' => [...]]])`. Each entry carries
	 * `userId`/`callInstanceId`/`code` so the client can replay it through
	 * its existing per-user `#onPullEventHangup` path. Replaces N × `sendHangup`
	 * (each fanning out to all call participants).
	 *
	 * @param array $disconnectedUsers list of objects with `userId` and `callInstanceId` (matches `DTO\CallUserRequest::disconnectedUsers`)
	 */
	public function sendUsersHangup(array $disconnectedUsers, int $code = 200): bool
	{
		if ($disconnectedUsers === [])
		{
			return true;
		}

		$payload = [];
		$senderIds = [];
		foreach ($disconnectedUsers as $user)
		{
			$senderId = (int)($user->userId ?? 0);
			if ($senderId <= 0)
			{
				continue;
			}
			$senderIds[] = $senderId;
			$payload[] = [
				'userId' => $senderId,
				'senderId' => $senderId,
				'callInstanceId' => $user->callInstanceId ?? null,
				'code' => $code,
			];
		}
		if ($payload === [])
		{
			return true;
		}

		if (!Loader::includeModule('pull'))
		{
			return false;
		}

		$callUsers = $this->filterFanOutRecipients([CallUser::STATE_READY, CallUser::STATE_CALLING], $senderIds);
		if ($callUsers === [])
		{
			return true;
		}

		Pull\Event::add($callUsers, [
			'module_id' => 'call',
			'command' => 'Call::usersHangup',
			'params' => [
				'call' => [
					'ID' => $this->call->getId(),
					'UUID' => $this->call->getUuid(),
					'PROVIDER' => $this->call->getProvider(),
					'SCHEME' => $this->call->getScheme(),
				],
				'callId' => $this->call->getId(),
				'code' => $code,
				'users' => $payload,
			],
			'expiry' => 3600,
		]);

		return true;
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

			Pull\Event::send();
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

			Pull\Event::send();
		}
	}

	public function sendFinishToInitiator(int $user): bool
	{
		return $this->sendFinishInternal([$user], false);
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

		Pull\Event::add($users, [
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

		Pull\Event::add($users, [
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

		Pull\Push::add($users, [
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
		$key = (int)$userId;
		if (isset($this->callInfoCache[$key]))
		{
			return $this->callInfoCache[$key];
		}

		$payload = [
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

		$this->callInfoCache[$key] = $payload;

		return $payload;
	}
}
