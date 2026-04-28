<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter\Updater\Delete;

use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\V2\Message\Counter\CounterOverflowService;
use Bitrix\Im\V2\Chat;

class Executor
{
	private ?array $resolvedArray = null;

	public function __construct(
		private readonly ScopeStep $scope,
		private readonly AudienceStep $audience
	) {}

	public function execute(): DeleteResult
	{
		$filter = $this->buildFilter();
		if (empty($filter))
		{
			return new DeleteResult(0, []);
		}

		$chatIds = $this->getChatIds();
		$deletedCount = MessageUnreadTable::deleteByFilter($filter);
		$this->deleteOverflow();
		$this->clearCache();

		return new DeleteResult($deletedCount, $chatIds);
	}

	protected function buildFilter(): array
	{
		$filter = [];

		$chatIds = $this->getChatIds();
		$toMessageId = $this->scope->getToMessageId();
		$messageIds = $this->scope->getMessageIds();
		$excludeMessageIds = $this->scope->getExcludeMessageIds();
		if ($chatIds !== null)
		{
			if (empty($chatIds))
			{
				return [];
			}

			if (count($chatIds) === 1)
			{
				$filter['=CHAT_ID'] = array_values($chatIds)[0];
			}
			else
			{
				$filter['@CHAT_ID'] = $chatIds;
			}
		}

		if ($toMessageId !== null)
		{
			$filter['<=MESSAGE_ID'] = $toMessageId;
		}
		if (!empty($messageIds))
		{
			$filter['@MESSAGE_ID'] = $messageIds;
		}
		if (!empty($excludeMessageIds))
		{
			$filter['!@MESSAGE_ID'] = $excludeMessageIds;
		}

		if (!$this->audience->isForAll())
		{
			$filter['=USER_ID'] = $this->audience->getUserId();
		}

		return $filter;
	}

	protected function deleteOverflow(): void
	{
		$chatIds = $this->getChatIds();
		if ($chatIds !== null && empty($chatIds))
		{
			return;
		}

		$userId = $this->audience->getUserId();

		CounterOverflowService::deleteByScope($chatIds, $userId);
	}

	protected function getChatIds(): ?array
	{
		if ($this->resolvedArray !== null)
		{
			return $this->resolvedArray;
		}

		if ($this->scope->getParentId() !== null)
		{
			$this->resolvedArray = $this->getChatIdsByParent();
		}
		else if ($this->scope->getType() !== null)
		{
			$this->resolvedArray = $this->getChatIdsByType($this->scope->getType());
		}
		else
		{
			$this->resolvedArray = $this->scope->getChatIds();
		}

		return $this->resolvedArray;
	}

	protected function getChatIdsByParent(): array
	{
		$query = MessageUnreadTable::query()
			->setSelect(['CHAT_ID'])
			->where('PARENT_ID', $this->scope->getParentId())
			->addGroup('CHAT_ID')
		;

		if ($this->audience->getUserId() !== null)
		{
			$query->where('USER_ID', $this->audience->getUserId());
		}

		return array_map('intval', array_column($query->fetchAll() ?: [], 'CHAT_ID'));
	}

	protected function getChatIdsByType(Chat\Type $type): array
	{
		$query = MessageUnreadTable::query()
			->setSelect(['CHAT_ID'])
			->setDistinct()
			->where('CHAT_TYPE', $type->literal)
		;
		if ($type->entityType)
		{
			$query->where('CHAT.ENTITY_TYPE', $type->entityType);
		}
		if ($this->audience->getUserId() !== null)
		{
			$query->where('USER_ID', $this->audience->getUserId());
		}

		$rows = $query->fetchAll();

		return array_map('intval', array_column($rows, 'CHAT_ID'));
	}

	protected function clearCache(): void
	{
		if ($this->audience->isForAll())
		{
			$invalidateFor = $this->audience->getAffectedUserIds();
			if ($invalidateFor !== null)
			{
				foreach ($invalidateFor as $userId)
				{
					$this->scope->getCache()->remove($userId);
				}
			}
			else
			{
				$this->scope->getCache()->removeAll();
			}
		}
		else
		{
			$userId = $this->audience->getUserId();
			if ($userId !== null)
			{
				$this->scope->getCache()->remove($userId);
			}
		}
	}
}
