<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\View;

use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\Model\MessageViewedTable;
use Bitrix\Im\V2\Common\RowsToMapHelper;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Type\DateTime;

class ViewProvider
{
	public function getLastUnviewedMessages(Message $endMessage, int $userId): MessageCollection
	{
		$startMessageId = $this->getFirstUnviewedMessageId($endMessage, $userId);

		$raw = MessageTable::query()
			->setSelect(['ID'])
			->where('CHAT_ID', $endMessage->getChatId())
			->where('ID', '>=', $startMessageId)
			->where('ID', '<=', $endMessage->getId())
			->setOrder(['DATE_CREATE' => 'DESC', 'ID' => 'DESC'])
			->setLimit(100)
			->fetchAll() ?: []
		;

		$ids = array_map('intval', array_column($raw, 'ID'));
		if (empty($ids))
		{
			return new MessageCollection();
		}

		return new MessageCollection($ids);
	}

	public function getDateViewedByMessageId(int $messageId, int $userId): ?DateTime
	{
		return $this->getDatesViewedByMessageIdsForUsers($messageId, [$userId])[$userId] ?? null;
	}

	public function getDatesViewedByMessageIdsForUsers(int $messageId, array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$raw = MessageViewedTable::query()
			->setSelect(['USER_ID', 'DATE_CREATE'])
			->where('MESSAGE_ID', $messageId)
			->whereIn('USER_ID', $userIds)
			->fetchAll() ?: []
		;

		return array_column($raw, 'DATE_CREATE', 'USER_ID');
	}

	public function getViewerIds(int $messageId): array
	{
		$raw = MessageViewedTable::query()
			->setSelect(['USER_ID'])
			->where('MESSAGE_ID', $messageId)
			->setOrder(['ID' => 'ASC'])
			->fetchAll()
		;

		$ids = [];
		foreach ($raw as $item)
		{
			$ids[$item['USER_ID']] = (int)$item['USER_ID'];
		}

		return $ids;
	}

	public function getViewStatuses(array $messageIds, int $userId): array
	{
		if (empty($messageIds))
		{
			return [];
		}

		$query = MessageViewedTable::query()
			->setSelect(['MESSAGE_ID'])
			->whereIn('MESSAGE_ID', $messageIds)
			->where('USER_ID', $userId)
		;

		$result = [];
		$viewedMessages = RowsToMapHelper::mapIntToInt($query->fetchAll(), 'MESSAGE_ID', 'MESSAGE_ID');
		foreach ($messageIds as $messageId)
		{
			$result[$messageId] = isset($viewedMessages[$messageId]);
		}

		return $result;
	}

	protected function getFirstUnviewedMessageId(Message $endMessage, int $userId): int
	{
		$id = $this->getLastViewedMessageId($endMessage->getChatId(), $userId);
		if ($id !== null)
		{
			return $id + 1;
		}

		return $endMessage->getChat()->getStartId($userId);
	}

	protected function getLastViewedMessageId(int $chatId, int $userId): ?int
	{
		$result = MessageViewedTable::query()
			->setSelect(['LAST_VIEWED' => new ExpressionField('LAST_VIEWED', 'MAX(%s)', ['MESSAGE_ID'])])
			->where('CHAT_ID', $chatId)
			->where('USER_ID', $userId)
			->fetch()
		;

		return ($result && isset($result['LAST_VIEWED'])) ? (int)$result['LAST_VIEWED'] : null;
	}
}
