<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Scheduled;

use Bitrix\Im\Model\ScheduledMessageTable;
use Bitrix\Im\V2\Message\Scheduled\GetList\ScheduledMessageCursor;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;

class ScheduledMessageProvider
{
	public function getById(int $id): ?ScheduledMessage
	{
		$scheduledMessage = new ScheduledMessage($id);

		if ($scheduledMessage->getId() === null)
		{
			return null;
		}

		return $scheduledMessage;
	}

	public function getByUuidAndAuthorId(string $uuid, int $authorId): ?ScheduledMessage
	{
		if ($authorId <= 0)
		{
			return null;
		}

		$row = ScheduledMessageTable::query()
			->setSelect(['*'])
			->where('UUID', $uuid)
			->where('AUTHOR_ID', $authorId)
			->fetch()
		;

		if (!$row)
		{
			return null;
		}

		return new ScheduledMessage($row);
	}

	public function getScheduledCountByChatId(int $chatId): int
	{
		return ScheduledMessageTable::getCount([
			'=CHAT_ID' => $chatId,
			'=STATUS' => ScheduledMessageStatus::Scheduled->value,
		]);
	}

	/**
	 * @return ScheduledMessage[]
	 */
	public function getList(
		int $authorId,
		int $chatId,
		int $limit,
		?ScheduledMessageCursor $lastCursor = null,
	): array
	{
		if ($authorId <= 0 || $chatId <= 0)
		{
			return [];
		}

		$query = ScheduledMessageTable::query()
			->setSelect(['*'])
			->where('AUTHOR_ID', $authorId)
			->where('CHAT_ID', $chatId)
			->setLimit($limit)
			->setOrder(['SEND_AT' => 'ASC', 'ID' => 'ASC'])
		;

		if (isset($lastCursor))
		{
			$query->where(Query::filter()
				->logic('or')
				->where('SEND_AT', '>', $lastCursor->getSendAt())
				->where(Query::filter()
					->where('SEND_AT', '=', $lastCursor->getSendAt())
					->where('ID', '>', $lastCursor->getScheduledMessageId())
				)
			);
		}

		$result = $query->exec();

		$scheduledMessages = [];
		while ($row = $result->fetch())
		{
			$scheduledMessages[] = new ScheduledMessage($row);
		}

		return $scheduledMessages;
	}

	/**
	 * @return int[]
	 */
	public function getExpiredIds(DateTime $expiredAt, int $limit): array
	{
		$rows = ScheduledMessageTable::query()
			->setSelect(['ID'])
			->where('STATUS', ScheduledMessageStatus::Scheduled->value)
			->where('SEND_AT', '<', $expiredAt)
			->setOrder(['SEND_AT' => 'ASC', 'ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		$ids = array_map(fn(array $row): int => (int)$row['ID'], $rows);

		return $ids;
	}

	/**
	 * @param int[] $ids
	 *
	 * @return ScheduledMessage[]
	 */
	public function getFailedByIds(array $ids): array
	{
		if ($ids === [])
		{
			return [];
		}

		$query = ScheduledMessageTable::query()
			->setSelect(['*'])
			->whereIn('ID', $ids)
			->where('STATUS', ScheduledMessageStatus::Failed->value)
		;

		$result = $query->exec();

		$scheduledMessages = [];
		while ($row = $result->fetch())
		{
			$scheduledMessages[] = new ScheduledMessage($row);
		}

		return $scheduledMessages;
	}
}
