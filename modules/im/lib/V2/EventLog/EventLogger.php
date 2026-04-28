<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\EventLog;

use Bitrix\Im\Bot;
use Bitrix\Im\Model\EventLogTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Entity\User\Data\BotData;
use Bitrix\Im\V2\Event\EventPayload;
use Bitrix\Im\V2\Rest\OutputFilter;
use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Web\Json;

class EventLogger
{
	private PendingCache $pendingCache;

	public function __construct(?PendingCache $pendingCache = null)
	{
		$this->pendingCache = $pendingCache ?? new PendingCache();
	}

	// region Bot events

	public function logBotMessageEvent(array $botExecModule, string $eventType, array $v2Payload): void
	{
		$eventPayload = new EventPayload();
		foreach ($botExecModule as $botData)
		{
			$botId = (int)($botData['BOT_ID'] ?? 0);
			if ($botId <= 0 || !$this->isFetchBot($botId))
			{
				continue;
			}

			$this->writeBotEventToLog($botId, $eventType, $v2Payload, $eventPayload);
		}
	}

	public function logJoinChat(array $botData, string $eventType, array $v2Payload): void
	{
		$botId = (int)($botData['BOT_ID'] ?? 0);
		if ($botId <= 0 || !$this->isFetchBot($botId))
		{
			return;
		}

		$this->writeBotEventToLog($botId, $eventType, $v2Payload);
	}

	public function logBotDelete(int $botId, string $eventType, array $v2Payload): void
	{
		if (!$this->isFetchBot($botId))
		{
			return;
		}

		$this->writeBotEventToLog($botId, $eventType, $v2Payload);
	}

	public function logContextGet(array $botsForEvent, string $eventType, array $v2Payload): void
	{
		$eventPayload = new EventPayload();
		foreach ($botsForEvent as $botData)
		{
			$botId = (int)($botData['BOT_ID'] ?? 0);
			if ($botId <= 0 || !$this->isFetchBot($botId))
			{
				continue;
			}

			$this->writeBotEventToLog($botId, $eventType, $v2Payload, $eventPayload);
		}
	}

	public function logCommandAdd(array $commandList, string $eventType, int $messageId, array $messageFields): void
	{
		$eventPayload = new EventPayload();
		foreach ($commandList as $commandData)
		{
			$botId = (int)($commandData['BOT_ID'] ?? 0);
			if ($botId <= 0 || !$this->isFetchBot($botId))
			{
				continue;
			}

			$v2Payload = $eventPayload->commandAdd($commandData, $messageId, $messageFields);
			$this->writeBotEventToLog($botId, $eventType, $v2Payload, $eventPayload);
		}
	}

	public function logReactionChange(int $botId, string $eventType, array $v2Payload): void
	{
		if (!$this->isFetchBot($botId))
		{
			return;
		}

		$this->writeBotEventToLog($botId, $eventType, $v2Payload);
	}

	// endregion

	// region User events

	/** $v2Payload accepts Closure for lazy building (skipped when no subscribers). */
	public function logUserMessageEvent(string $eventType, array|\Closure $v2Payload, array $messageFields): void
	{
		$userIds = $this->getChatUserIds($messageFields);
		if (empty($userIds))
		{
			return;
		}

		$subscribedIds = $this->filterSubscribedUsers($userIds);
		if (empty($subscribedIds))
		{
			return;
		}

		if ($v2Payload instanceof \Closure)
		{
			$v2Payload = $v2Payload();
		}

		$this->writeToLog($subscribedIds, $eventType, [$v2Payload]);
	}

	public function logUserReactionEvent(string $eventType, array|\Closure $v2Payload, int $chatId): void
	{
		if ($chatId <= 0)
		{
			return;
		}

		$chat = \Bitrix\Im\V2\Chat::getInstance($chatId);
		$userIds = $chat->getRelations()->getUserIds();

		$subscribedIds = $this->filterSubscribedNonBotUsers($userIds);
		if (empty($subscribedIds))
		{
			return;
		}

		if ($v2Payload instanceof \Closure)
		{
			$v2Payload = $v2Payload();
		}

		$this->writeToLog($subscribedIds, $eventType, [$v2Payload]);
	}

	public function logUserChatEvent(string $eventType, array $payloads, int $chatId, ?array $userIds = null): void
	{
		if ($chatId <= 0 || empty($payloads))
		{
			return;
		}

		$userIds ??= \Bitrix\Im\V2\Chat::getInstance($chatId)->getRelations()->getUserIds();

		$subscribedIds = $this->filterSubscribedNonBotUsers($userIds);
		if (empty($subscribedIds))
		{
			return;
		}

		$resolvedPayloads = array_map(
			static fn($p) => $p instanceof \Closure ? $p() : $p,
			$payloads
		);

		$this->writeToLog($subscribedIds, $eventType, $resolvedPayloads);
	}

	// endregion

	// region Internals

	public function getPendingCache(): PendingCache
	{
		return $this->pendingCache;
	}

	private function isFetchBot(int $botId): bool
	{
		$data = BotData::getInstance($botId)->toArray();

		return ($data['EVENT_MODE'] ?? Bot::EVENT_MODE_WEBHOOK) === Bot::EVENT_MODE_FETCH;
	}

	private function isSubscribedUser(int $userId): bool
	{
		$user = \Bitrix\Im\User::getInstance($userId);
		$fields = $user->getFields();

		return ($fields['event_log'] ?? 'N') === 'Y';
	}

	private function filterSubscribedUsers(array $userIds): array
	{
		return array_filter($userIds, fn(int $userId) => $this->isSubscribedUser($userId));
	}

	private function filterSubscribedNonBotUsers(array $userIds): array
	{
		return array_filter($userIds, function (int $userId): bool {
			return !\Bitrix\Im\User::getInstance($userId)->isBot()
				&& $this->isSubscribedUser($userId);
		});
	}

	private function writeBotEventToLog(
		int $botId,
		string $eventType,
		array $v2Payload,
		?EventPayload $eventPayload = null
	): void
	{
		$eventPayload ??= new EventPayload();
		$v2Payload['bot'] = $eventPayload->loadBotRest($botId);
		$this->writeToLog([$botId], $eventType, [$v2Payload]);
	}

	private function writeToLog(array $userIds, string $eventType, array $eventDataItems): void
	{
		$now = new DateTime();
		$rows = [];

		foreach ($eventDataItems as $eventData)
		{
			$filtered = OutputFilter::filterForEventLog($eventData);
			$isPrivate = ($filtered['chat']['type'] ?? '') === 'private';
			$baseJson = $isPrivate ? null : Json::encode($filtered);

			foreach ($userIds as $userId)
			{
				$json = $isPrivate
					? Json::encode($this->personalizeDialogId($filtered, $userId))
					: $baseJson;

				$rows[] = [
					'USER_ID' => $userId,
					'EVENT_TYPE' => $eventType,
					'EVENT_DATA' => Emoji::encode($json),
					'DATE_CREATE' => $now,
				];
			}
		}

		if (!empty($rows))
		{
			Application::getConnection()->addMulti(
				EventLogTable::getTableName(),
				$rows,
			);

			$this->invalidateCacheForUsers($userIds);
		}
	}

	private function personalizeDialogId(array $eventData, int $userId): array
	{
		if (!isset($eventData['chat']['id']))
		{
			return $eventData;
		}

		$chat = Chat::getInstance((int)$eventData['chat']['id']);
		if ($chat instanceof Chat\PrivateChat)
		{
			$eventData['chat']['dialogId'] = $chat->getDialogId($userId);
		}

		return $eventData;
	}

	private function invalidateCacheForUsers(array $userIds): void
	{
		foreach ($userIds as $userId)
		{
			$this->pendingCache->invalidate($userId);
		}
	}

	private function getChatUserIds(array $messageFields): array
	{
		if (($messageFields['MESSAGE_TYPE'] ?? '') === \IM_MESSAGE_PRIVATE)
		{
			return array_filter([
				(int)($messageFields['FROM_USER_ID'] ?? 0),
				(int)($messageFields['TO_USER_ID'] ?? 0),
			]);
		}

		$chatId = (int)($messageFields['TO_CHAT_ID'] ?? $messageFields['CHAT_ID'] ?? 0);
		if ($chatId <= 0)
		{
			return [];
		}

		$chat = \Bitrix\Im\V2\Chat::getInstance($chatId);

		return $chat->getRelations()->filterActive()->getUserIds();
	}

	// endregion
}
