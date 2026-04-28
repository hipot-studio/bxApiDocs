<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter;

use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Counter\CounterOverflowService;
use Bitrix\Im\V2\Reading\Counter\Internal\CountersCache;
use Bitrix\Im\V2\Reading\Counter\Updater\Delete\ScopeStep;
use Bitrix\Im\V2\Relation;
use Bitrix\Im\V2\RelationCollection;
use Bitrix\Main\ORM\Fields\ExpressionField;

class CountersUpdater
{
	public function __construct(
		protected readonly CountersCache $cache,
		protected readonly CounterOverflowService $overflowService
	) {}

	public function addForUsers(Message $message, RelationCollection $relations): void
	{
		$insertFields = [];
		$usersIds = [];

		foreach ($relations as $relation)
		{
			if ($relation->getMessageType() !== \IM_MESSAGE_SYSTEM && $message->getActionContextUserId() === $relation->getUserId())
			{
				continue;
			}

			$insertFields[] = $this->prepareInsertFields($message, $relation);
			$usersIds[] = $relation->getUserId();
		}

		MessageUnreadTable::multiplyInsertWithoutDuplicate($insertFields);
		foreach ($usersIds as $userId)
		{
			$this->cache->remove($userId);
		}
	}

	public function delete(): ScopeStep
	{
		return new ScopeStep($this->cache, $this->overflowService);
	}

	public function addStartingFrom(int $messageId, Relation $relation): void
	{
		$query = MessageTable::query()
			->setSelect([
				'ID_CONST' => new ExpressionField('ID_CONST', '0'),
				'USER_ID_CONST' => new ExpressionField('USER_ID_CONST', (string)$relation->getUserId()),
				'CHAT_ID_CONST' => new ExpressionField('CHAT_ID', (string)$relation->getChatId()),
				'MESSAGE_ID' => 'ID',
				'IS_MUTED' => new ExpressionField('IS_MUTED', $relation->getNotifyBlock() ? "'Y'" : "'N'"),
				'CHAT_TYPE' => new ExpressionField('CHAT_TYPE', "'{$relation->getMessageType()}'"),
				'DATE_CREATE',
				'PARENT_ID' => new ExpressionField('PARENT_ID', (string)$relation->getChat()->getParentChatId()),
			])
			->where('CHAT_ID', $relation->getChatId())
			->where('MESSAGE_ID', '>=', $messageId)
		;
		if ($relation->getMessageType() !== \IM_MESSAGE_SYSTEM)
		{
			$query->whereNot('AUTHOR_ID', $relation->getUserId());
		}

		MessageUnreadTable::insertSelect($query, ['ID', 'USER_ID', 'CHAT_ID', 'MESSAGE_ID', 'IS_MUTED', 'CHAT_TYPE', 'DATE_CREATE', 'PARENT_ID']);

		$this->cache->remove($relation->getUserId());
	}

	protected function prepareInsertFields(Message $message, Relation $relation): array
	{
		return [
			'MESSAGE_ID' => $message->getMessageId(),
			'CHAT_ID' => $message->getChatId(),
			'USER_ID' => $relation->getUserId(),
			'CHAT_TYPE' => $relation->getMessageType(),
			'IS_MUTED' => $relation->getNotifyBlock() ? 'Y' : 'N',
			'PARENT_ID' => $message->getChat()->getParentChatId(),
		];
	}
}
