<?php

namespace Bitrix\Im\V2\Message\Send\Push;

use Bitrix\Im\V2\Message\Send\PushService;

class PrivatePushService extends PushService
{
	public function sendPush(array $counters = []): void
	{
		if (!$this->isPullEnable() || !$this->sendingConfig->addRecent())
		{
			return;
		}

		$pullMessages = $this->getPullMessages($counters);

		foreach ($pullMessages as $userId => $pullMessage)
		{
			\Bitrix\Pull\Event::add($userId, $pullMessage);
			$this->mobilePush->sendForPrivateMessage($userId, $pullMessage);
		}
	}

	protected function getPullMessages(array $counters): array
	{
		$chat = $this->message->getChat();
		$basePullMessage = $this->getBasePullMessage();

		$memberIds = array_values($chat->getRelations()->getUserIds());
		$firstUserId = $memberIds[0] ?? null;
		$secondUserId = $memberIds[1] ?? null;

		return match (count($memberIds))
		{
			1 => [$firstUserId => $this->getPullMessage($basePullMessage, $firstUserId, $firstUserId, $counters)],
			2 => [
				$firstUserId => $this->getPullMessage($basePullMessage, $firstUserId, $secondUserId, $counters),
				$secondUserId => $this->getPullMessage($basePullMessage, $secondUserId, $firstUserId, $counters),
			],
			default => [],
		};
	}

	protected function getBasePullMessage(): array
	{
		return [
			'module_id' => 'im',
			'command' => 'message',
			'params' => $this->pushFormatter->format(),
			'extra' => \Bitrix\Im\Common::getPullExtra(),
		];
	}

	protected function getPullMessage(array $basePullMessage, int $userId, int $opponentId, array $counters): array
	{
		$basePullMessage['params']['dialogId'] = $opponentId;
		$basePullMessage['params']['counter'] = $counters[$userId] ?? 0;

		return $basePullMessage;
	}
}