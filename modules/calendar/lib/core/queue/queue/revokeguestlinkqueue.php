<?php

namespace Bitrix\Calendar\Core\Queue\Queue;

use Bitrix\Calendar\Internals\QueueHandledMessageTable;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

class RevokeGuestLinkQueue
{
	private const RETRY_DELAY = '+5 minutes';

	public function enqueue(
		int $eventId,
		int $chatId = 0,
		int $seriesRootId = 0,
		int $creatorId = 0,
		int $ownerId = 0,
	): void
	{
		$payload = json_encode([
			'eventId' => $eventId,
			'chatId' => $chatId,
			'seriesRootId' => $seriesRootId,
			'creatorId' => $creatorId,
			'ownerId' => $ownerId,
			'attempts' => 0,
		], JSON_THROW_ON_ERROR);
		$result = QueueHandledMessageTable::add([
			'MESSAGE_ID' => 0,
			'QUEUE_ID' => QueueRegistry::QUEUE_LIST['RevokeGuestLink'],
			'HASH' => $payload,
			'DATE_CREATE' => new DateTime(),
		]);
		if (!$result->isSuccess())
		{
			throw new SystemException(implode(', ', $result->getErrorMessages()));
		}
	}

	/**
	 * @return array<int, array{id: int, eventId: int, chatId: int, seriesRootId: int, creatorId: int, ownerId: int, attempts: int}>
	 */
	public function getBatch(int $limit): array
	{
		$rows = QueueHandledMessageTable::getList([
			'select' => ['ID', 'HASH'],
			'filter' => [
				'=QUEUE_ID' => QueueRegistry::QUEUE_LIST['RevokeGuestLink'],
				'<=DATE_CREATE' => new DateTime(),
			],
			'order' => ['DATE_CREATE' => 'ASC', 'ID' => 'ASC'],
			'limit' => $limit,
		]);

		$batch = [];
		while ($row = $rows->fetch())
		{
			$payload = json_decode((string)$row['HASH'], true);
			if (!is_array($payload))
			{
				$payload = ['eventId' => (int)$row['HASH']];
			}
			$batch[] = [
				'id' => (int)$row['ID'],
				'eventId' => (int)($payload['eventId'] ?? 0),
				'chatId' => (int)($payload['chatId'] ?? 0),
				'seriesRootId' => (int)($payload['seriesRootId'] ?? 0),
				'creatorId' => (int)($payload['creatorId'] ?? 0),
				'ownerId' => (int)($payload['ownerId'] ?? 0),
				'attempts' => (int)($payload['attempts'] ?? 0),
			];
		}

		return $batch;
	}

	/**
	 * @param int[] $ids
	 */
	public function acknowledge(array $ids): void
	{
		$ids = $this->normalizeIds($ids);
		if ($ids !== [])
		{
			QueueHandledMessageTable::deleteByFilter(['@ID' => $ids]);
		}
	}

	/**
	 * @param array<int, array{id: int, eventId: int, chatId: int, seriesRootId: int, creatorId: int, ownerId: int, attempts: int}> $messages
	 */
	public function defer(array $messages): void
	{
		$date = (new DateTime())->add(self::RETRY_DELAY);

		foreach ($messages as $message)
		{
			$id = (int)($message['id'] ?? 0);
			if ($id <= 0)
			{
				continue;
			}

			$payload = json_encode([
				'eventId' => (int)($message['eventId'] ?? 0),
				'chatId' => (int)($message['chatId'] ?? 0),
				'seriesRootId' => (int)($message['seriesRootId'] ?? 0),
				'creatorId' => (int)($message['creatorId'] ?? 0),
				'ownerId' => (int)($message['ownerId'] ?? 0),
				'attempts' => (int)($message['attempts'] ?? 0) + 1,
			], JSON_THROW_ON_ERROR);
			$result = QueueHandledMessageTable::update($id, [
				'MESSAGE_ID' => 0,
				'HASH' => $payload,
				'DATE_CREATE' => $date,
			]);
			if (!$result->isSuccess())
			{
				throw new SystemException(implode(', ', $result->getErrorMessages()));
			}
		}
	}

	private function normalizeIds(array $ids): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', $ids),
			static fn(int $id): bool => $id > 0,
		)));
	}
}
