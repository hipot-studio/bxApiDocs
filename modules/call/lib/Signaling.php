<?php

namespace Bitrix\Call;

use Bitrix\Call\Settings;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Im\Call\Call;
use Bitrix\Im\Call\Integration\Chat;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class Signaling extends \Bitrix\Im\Call\Signaling
{
	public function sendCallInviteToUser(int $senderId, int $toUserId, $isLegacyMobile, bool $video = false, bool $sendPush = true): void
	{
		$parentCall = $this->call->getParentId() ? Call::loadWithId($this->call->getParentId()) : null;
		$skipPush = $parentCall ?  $parentCall->getUsers() : [];
		$skipPush = array_flip($skipPush);

		$associatedEntity = $this->call->getAssociatedEntity();
		$isBroadcast = ($associatedEntity instanceof Chat) && $associatedEntity->isBroadcast();

		$config = [
			'callToken' => JwtCall::getCallToken($this->call->getAssociatedEntity()->getChatId()),
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

		$this->send('Call::incoming', $toUserId, $config, $push);
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
		$push = [
			'message' => $pushText,
			'expiry' => 0,
			'params' => [
				'ACTION' => 'IMINV_'.$this->call->getId()."_".time()."_".($video ? 'Y' : 'N'),
				'PARAMS' => [
					'callToken' => JwtCall::getCallToken($this->call->getAssociatedEntity()->getChatId()),
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

	public static function sendChangedCallV2Enable(bool $isJwtEnabled, bool $isPlainUseJwt, string $callBalancerUrl): void
	{
		if (Loader::includeModule('pull'))
		{
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

	protected function getCallAiSettings(): array
	{
		return [
			'serviceEnabled' => Settings::isAIServiceEnabled(),
			'settingsEnabled' => CallAISettings::isEnableBySettings(),
			'recordingMinUsers' => CallAISettings::getRecordMinUsers(),
			'agreementAccepted' => CallAISettings::isAgreementAccepted(),
			'tariffAvailable' => CallAISettings::isTariffAvailable(),
			'baasAvailable' => CallAISettings::isBaasServiceHasPackage(),
		];
	}

	protected function getCallInfoForSend($userId): array
	{
		return [
			'uuid' => $this->call->getUuid(),
			'parentUuid' => $this->call->getParentUuid(),
			'provider' => $this->call->getProvider(),
			'type' => $this->call->getType(),
			'initiatorId' => $this->call->getInitiatorId(),
			'startDate' => $this->call->getStartDate(),
			'associatedEntity' => $this->call->getAssociatedEntity()->toArray($userId),
			'userCounter' => count($this->call->getUsers()),
		];
	}
}