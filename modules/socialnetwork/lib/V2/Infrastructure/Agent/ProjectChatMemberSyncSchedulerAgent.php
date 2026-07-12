<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Agent;

use Bitrix\Main\Update\Stepper;
use Bitrix\Socialnetwork\Collab\Internals\CollabOptionTable;
use Bitrix\Socialnetwork\Log\Logger;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\ConvertProgressMapper;
use Throwable;

/**
 * Степпер-агент, который проходит по всем уже сконвертированным группам
 * и для каждой планирует агента {@see ProjectChatMemberSyncAgent}.
 *
 * Запуск:
 *   ProjectChatMemberSyncSchedulerAgent::bind(10);
 */
class ProjectChatMemberSyncSchedulerAgent extends Stepper
{
	private const BATCH_SIZE = 5;

	protected static $moduleId = 'socialnetwork';

	public function execute(array &$option): bool
	{
		$lastOptionId = (int)($option['lastOptionId'] ?? 0);

		$groups = $this->getConvertedGroups($lastOptionId, self::BATCH_SIZE);

		if ($groups === [])
		{
			return self::FINISH_EXECUTION;
		}

		foreach ($groups as $group)
		{
			$groupId = $group['groupId'];
			$chatId = $group['groupChatId'];

			if ($groupId <= 0 || $chatId <= 0)
			{
				continue;
			}

			try
			{
				$this->bindSyncAgent($groupId, $chatId);
			}
			catch (Throwable $exception)
			{
				$this->logBindError($groupId, $exception);
			}
		}

		if (count($groups) < self::BATCH_SIZE)
		{
			return self::FINISH_EXECUTION;
		}

		$option['lastOptionId'] = min(array_column($groups, 'optionId'));

		return self::CONTINUE_EXECUTION;
	}

	/**
	 * @return list<array{optionId: int, groupId: int, groupChatId: int}>
	 */
	protected function getConvertedGroups(int $lastOptionId, int $limit): array
	{
		$query = CollabOptionTable::query()
			->setSelect(['ID', 'COLLAB_ID'])
			->where('NAME', ConvertProgressMapper::CONVERT_STATUS)
			->whereLike('VALUE', 'completed_from%')
			->setOrder(['ID' => 'DESC'])
			->setLimit($limit)
		;

		if ($lastOptionId > 0)
		{
			$query->where('ID', '<', $lastOptionId);
		}

		$optionRows = $query->exec()->fetchAll();

		if ($optionRows === [])
		{
			return [];
		}

		$groupIds = array_map(
			static fn(array $row): int => (int)($row['COLLAB_ID'] ?? 0),
			$optionRows,
		);

		$chatMap = $this->resolveChatIds($groupIds);

		$groups = [];
		foreach ($optionRows as $optionRow)
		{
			$groupId = (int)($optionRow['COLLAB_ID'] ?? 0);

			$groups[] = [
				'optionId' => (int)($optionRow['ID'] ?? 0),
				'groupId' => $groupId,
				'groupChatId' => (int)($chatMap[$groupId] ?? 0),
			];
		}

		return $groups;
	}

	/**
	 * @param list<int> $groupIds
	 * @return array<int, int> groupId => chatId
	 */
	protected function resolveChatIds(array $groupIds): array
	{
		return Container::getInstance()
			->getProjectChatResolver()
			->getByProjectIds($groupIds)
		;
	}

	protected function bindSyncAgent(int $groupId, int $chatId): void
	{
		ProjectChatMemberSyncAgent::bind(
			delay: 10,
			withArguments: [$groupId, $chatId],
		);
	}

	protected function logBindError(int $groupId, Throwable $exception): void
	{
		Logger::log(
			data: [
				'type' => 'error',
				'message' => sprintf(
					'%s: failed to bind ProjectChatMemberSyncAgent for group %d: %s',
					static::class,
					$groupId,
					$exception->getMessage(),
				),
				'file' => $exception->getFile(),
				'line' => $exception->getLine(),
			],
			marker: 'PROJECT_AI_CONVERSION',
		);
	}
}

