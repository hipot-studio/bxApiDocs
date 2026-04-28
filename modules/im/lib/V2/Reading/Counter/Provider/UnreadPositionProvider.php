<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter\Provider;

use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\V2\Common\RowsToMapHelper;
use Bitrix\Main\ORM\Fields\ExpressionField;

class UnreadPositionProvider
{
	public function getForChat(int $chatId, int $userId): ?int
	{
		$result = $this->getForChats([$chatId], $userId);

		return $result[$chatId] ?? null;
	}

	public function getForChats(array $chatIds, int $userId): array
	{
		if (empty($chatIds))
		{
			return [];
		}

		$result = MessageUnreadTable::query()
			->setSelect(['CHAT_ID', 'UNREAD_ID' => new ExpressionField('UNREAD_ID', 'MIN(%s)', ['MESSAGE_ID'])])
			->whereIn('CHAT_ID', $chatIds)
			->where('USER_ID', $userId)
			->setGroup(['CHAT_ID'])
			->fetchAll()
		;

		return RowsToMapHelper::mapIntToInt($result, 'CHAT_ID', 'UNREAD_ID');
	}
}
