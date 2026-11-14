<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter\Updater\Delete;

use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\V2\Chat\Tree\ChatTreeFilterFactory;
use Bitrix\Im\V2\Chat\Tree\ChatTreeScope;
use Bitrix\Im\V2\Message\Counter\CounterOverflowService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ORM\Query\Query;

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
		$chatIds = $this->getChatIds() ?? [];
		if (empty($filter))
		{
			return new DeleteResult(chatIds: $chatIds);
		}

		// The owners are read ahead of the delete: once the rows are gone, nothing tells whose they were.
		$rowOwnerIds = $this->audience->getUserIds() === null ? null : $this->fetchRowOwnerIds($filter);
		if ($rowOwnerIds === [])
		{
			return new DeleteResult(chatIds: $chatIds);
		}

		$deletedCount = MessageUnreadTable::deleteByFilter($filter);
		$this->deleteOverflow($rowOwnerIds);
		$this->clearCache($rowOwnerIds);

		return new DeleteResult($deletedCount, $chatIds);
	}

	/**
	 * The users of the group who really hold rows in the scope. A bounded group is walked in batches
	 * of hundreds, and dropping the cache of every member of a batch would rebuild counters that have
	 * not changed for most of them.
	 *
	 * @return int[]
	 */
	protected function fetchRowOwnerIds(array $filter): array
	{
		$rows = MessageUnreadTable::query()
			->setSelect(['USER_ID'])
			->setDistinct()
			->setFilter($filter)
			->fetchAll()
		;

		return array_map('intval', array_column($rows, 'USER_ID'));
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

		$userIds = $this->audience->getUserIds();
		if ($userIds !== null)
		{
			if (empty($userIds))
			{
				return [];
			}

			$filter['@USER_ID'] = $userIds;
		}
		elseif (!$this->audience->isForAll())
		{
			$filter['=USER_ID'] = $this->audience->getUserId();
		}

		return $filter;
	}

	/**
	 * @param int[]|null $rowOwnerIds the users of a bounded group whose rows were found, null otherwise
	 */
	protected function deleteOverflow(?array $rowOwnerIds): void
	{
		$chatIds = $this->getChatIds();
		if ($chatIds !== null && empty($chatIds))
		{
			return;
		}

		if ($rowOwnerIds !== null)
		{
			// An overflow needs rows to overflow with, so its owners are among the owners of the rows.
			CounterOverflowService::deleteByChatIdsForUsers($chatIds ?? [], $rowOwnerIds);

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

		$treeScope = $this->scope->getTreeScope();
		if ($treeScope !== null)
		{
			$this->resolvedArray = $this->getChatIdsByTreeScope($treeScope);
		}
		else
		{
			$this->resolvedArray = $this->scope->getChatIds();
		}

		return $this->resolvedArray;
	}

	protected function getChatIdsByTreeScope(ChatTreeScope $treeScope): array
	{
		if (!$treeScope->isPossible())
		{
			return [];
		}

		$userId = $this->audience->getUserId();

		$makeQuery = static function () use ($userId): Query {
			$query = MessageUnreadTable::query()
				->setSelect(['CHAT_ID'])
				->setDistinct()
			;

			if ($userId !== null)
			{
				$query->where('USER_ID', $userId);
			}

			return $query;
		};

		return ServiceLocator::getInstance()
			->get(ChatTreeFilterFactory::class)
			->forTreeScopeUnreadCounters($treeScope)
			->fetchChatIds($makeQuery, 'CHAT_ID')
		;
	}

	/**
	 * @param int[]|null $rowOwnerIds the users of a bounded group whose rows were found, null otherwise
	 */
	protected function clearCache(?array $rowOwnerIds): void
	{
		if ($rowOwnerIds !== null)
		{
			foreach ($rowOwnerIds as $userId)
			{
				$this->scope->getCache()->remove($userId);
			}
		}
		elseif ($this->audience->isForAll())
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
