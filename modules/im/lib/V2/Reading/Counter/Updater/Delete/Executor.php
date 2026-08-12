<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter\Updater\Delete;

use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\V2\Chat\Tree\ChatTreeFilterFactory;
use Bitrix\Im\V2\Chat\Tree\ChatTreeScope;
use Bitrix\Im\V2\Message\Counter\CounterOverflowService;
use Bitrix\Main\DI\ServiceLocator;

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

		$query = MessageUnreadTable::query()
			->setSelect(['CHAT_ID'])
			->setDistinct()
		;

		if ($userId !== null)
		{
			$query->where('USER_ID', $userId);
		}

		ServiceLocator::getInstance()
			->get(ChatTreeFilterFactory::class)
			->forTreeScopeUnreadCounters($treeScope)
			->apply($query)
		;

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
