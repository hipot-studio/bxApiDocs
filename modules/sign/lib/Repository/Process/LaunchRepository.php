<?php

namespace Bitrix\Sign\Repository\Process;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Sign\Internal;
use Bitrix\Sign\Item\Process\Launch;
use Bitrix\Sign\Item\Process\LaunchItem;
use Bitrix\Sign\Type\DateTime;
use Bitrix\Sign\Type\Process\LaunchItemStatus;
use Bitrix\Sign\Type\Process\LaunchStatus;

/**
 * Storage of the launch groups. The repository opens no transaction of its own: the group and its
 * rows are written inside the transaction of the caller, and a transaction started here would nest
 * into it.
 */
class LaunchRepository
{
	/**
	 * Writes the group together with one queued row per given employee. The composition of the group
	 * is known at this moment and never grows later, so the member count of the group is the number
	 * of rows written and is taken from them, not from the caller.
	 *
	 * @param int[] $employeeIds
	 */
	public function add(Launch $launch, array $employeeIds): Result
	{
		$result = new Result();
		if (!$employeeIds)
		{
			return $result->addError(new Error('Launch group without employees'));
		}

		$launch->memberCount = count($employeeIds);
		$launchResult = Internal\Process\LaunchTable::add([
			'CREATED_BY' => $launch->createdById,
			'TEMPLATE_ID' => $launch->templateId,
			'ENTITY_TYPE_ID' => $launch->entityTypeId,
			'MEMBER_COUNT' => $launch->memberCount,
			'STATUS' => $launch->status->value,
			'PARAMETERS' => $launch->parameters,
			'QUEUED_AT' => $launch->queuedAt,
			'EXPIRES_AT' => $launch->expiresAt,
		]);
		if (!$launchResult->isSuccess())
		{
			return $launchResult;
		}

		$launch->id = (int)$launchResult->getId();

		// one statement for the whole selection: a group is as often a whole department as one employee
		$itemsResult = Internal\Process\LaunchItemTable::addMulti(
			array_map(
				static fn(int $employeeId): array => [
					'LAUNCH_ID' => $launch->id,
					'EMPLOYEE_ID' => $employeeId,
					'STATUS' => LaunchItemStatus::Queued->value,
				],
				array_values($employeeIds),
			),
			true,
		);

		return $itemsResult->isSuccess() ? $result : $itemsResult;
	}

	public function getById(int $launchId): ?Launch
	{
		if ($launchId < 1)
		{
			return null;
		}

		$row = Internal\Process\LaunchTable::query()
			->setSelect(['*'])
			->where('ID', $launchId)
			->fetch()
		;

		return $row ? $this->extractLaunchFromRow($row) : null;
	}

	/**
	 * The group a started process belongs to. The engine knows nothing about the group, so the way
	 * back goes through the row the identifier of the process was written into.
	 */
	public function getByWorkflowId(string $workflowId): ?Launch
	{
		if ($workflowId === '')
		{
			return null;
		}

		$row = Internal\Process\LaunchItemTable::query()
			->addSelect('LAUNCH_ID')
			->where('WORKFLOW_ID', $workflowId)
			->fetch()
		;

		return $row ? $this->getById((int)$row['LAUNCH_ID']) : null;
	}

	/**
	 * The row a started process belongs to. Relies on the index over the identifier of the process, which
	 * is what the events of the engine give and the only key they give.
	 */
	public function getItemByWorkflowId(string $workflowId): ?LaunchItem
	{
		if ($workflowId === '')
		{
			return null;
		}

		$row = Internal\Process\LaunchItemTable::query()
			->setSelect(['*'])
			->where('WORKFLOW_ID', $workflowId)
			->fetch()
		;

		return $row ? $this->extractLaunchItemFromRow($row) : null;
	}

	/**
	 * The row an item of the smart process was created for. Relies on the index over the element: the way
	 * back from the element of a process to the launch it came from has no other key.
	 */
	public function getItemByEntityId(int $entityId): ?LaunchItem
	{
		if ($entityId < 1)
		{
			return null;
		}

		$row = Internal\Process\LaunchItemTable::query()
			->setSelect(['*'])
			->where('ENTITY_ID', $entityId)
			->fetch()
		;

		return $row ? $this->extractLaunchItemFromRow($row) : null;
	}

	/**
	 * @return list<LaunchItem>
	 */
	public function listItems(int $launchId): array
	{
		if ($launchId < 1)
		{
			return [];
		}

		$rows = Internal\Process\LaunchItemTable::query()
			->setSelect(['*'])
			->where('LAUNCH_ID', $launchId)
			->addOrder('ID')
			->fetchAll()
		;

		return array_map($this->extractLaunchItemFromRow(...), $rows);
	}

	/**
	 * Rows of several groups at once, in the order they were written. The list of processes reads a whole
	 * page of groups per request, so a query per group would grow with the size of the page.
	 *
	 * @param int[] $launchIds
	 *
	 * @return list<LaunchItem>
	 */
	public function listItemsByLaunchIds(array $launchIds): array
	{
		$launchIds = $this->filterIds($launchIds);
		if ($launchIds === [])
		{
			return [];
		}

		$rows = Internal\Process\LaunchItemTable::query()
			->setSelect(['*'])
			->whereIn('LAUNCH_ID', $launchIds)
			->addOrder('ID')
			->fetchAll()
		;

		return array_map($this->extractLaunchItemFromRow(...), $rows);
	}

	/**
	 * A bounded keyset page of rows of several groups. The process list may inspect a group with hundreds
	 * of employees only to find the first visible rows, so its hot paths must not materialize the entire
	 * composition in one query.
	 *
	 * @param int[] $launchIds
	 * @param list<LaunchItemStatus> $statuses
	 *
	 * @return list<LaunchItem>
	 */
	public function listItemsPageByLaunchIds(
		array $launchIds,
		int $limit,
		int $afterId = 0,
		array $statuses = [],
	): array
	{
		$launchIds = $this->filterIds($launchIds);
		if ($launchIds === [] || $limit < 1 || $afterId < 0)
		{
			return [];
		}

		$query = Internal\Process\LaunchItemTable::query()
			->setSelect(['*'])
			->whereIn('LAUNCH_ID', $launchIds)
			->addOrder('ID')
			->setLimit($limit)
		;
		if ($afterId > 0)
		{
			$query->where('ID', '>', $afterId);
		}

		$statusValues = array_values(array_unique(array_map(
			static fn(LaunchItemStatus $status): string => $status->value,
			$statuses,
		)));
		if ($statusValues !== [])
		{
			$query->whereIn('STATUS', $statusValues);
		}

		$rows = $query->fetchAll();

		return array_map($this->extractLaunchItemFromRow(...), $rows);
	}

	/**
	 * Groups of one type of document, the newest first - the order the list of processes shows them in.
	 * Relies on the primary key for the order, so the window of the page costs no sorting of its own.
	 *
	 * @return list<Launch>
	 */
	public function listByEntityTypeId(int $entityTypeId, int $limit, int $offset = 0): array
	{
		if ($entityTypeId < 1 || $limit < 1 || $offset < 0)
		{
			return [];
		}

		$rows = Internal\Process\LaunchTable::query()
			->setSelect(['*'])
			->where('ENTITY_TYPE_ID', $entityTypeId)
			->addOrder('ID', 'DESC')
			->setLimit($limit)
			->setOffset($offset)
			->fetchAll()
		;

		return array_map($this->extractLaunchFromRow(...), $rows);
	}

	/**
	 * Rows waiting for their process, oldest first. Relies on the index over the status and the
	 * identifier: the queue is read on every step of the agent, so a full scan of the accumulated
	 * history is not affordable.
	 *
	 * @return list<LaunchItem>
	 */
	public function listQueuedItems(int $limit): array
	{
		if ($limit < 1)
		{
			return [];
		}

		$rows = Internal\Process\LaunchItemTable::query()
			->setSelect(['*'])
			->where('STATUS', LaunchItemStatus::Queued->value)
			->addOrder('ID')
			->setLimit($limit)
			->fetchAll()
		;

		return array_map($this->extractLaunchItemFromRow(...), $rows);
	}

	/**
	 * Groups whose waiting term has run out while they were not finished yet. Relies on the index
	 * over the status and the term.
	 *
	 * @return list<Launch>
	 */
	public function listExpired(DateTime $moment, int $limit): array
	{
		if ($limit < 1)
		{
			return [];
		}

		$rows = Internal\Process\LaunchTable::query()
			->setSelect(['*'])
			->whereIn('STATUS', array_map(
				static fn(LaunchStatus $status): string => $status->value,
				LaunchStatus::getUnfinished(),
			))
			->where('EXPIRES_AT', '<', $moment)
			->addOrder('ID')
			->setLimit($limit)
			->fetchAll()
		;

		return array_map($this->extractLaunchFromRow(...), $rows);
	}

	public function updateStatus(int $launchId, LaunchStatus $status): Result
	{
		return Internal\Process\LaunchTable::update($launchId, [
			'STATUS' => $status->value,
		]);
	}

	/**
	 * New term of the waiting of the group. The term is written apart from the status: a group whose rows
	 * were sent back into the queue keeps its composition and gets only its waiting counted anew.
	 */
	public function updateExpiresAt(int $launchId, DateTime $expiresAt): Result
	{
		return Internal\Process\LaunchTable::update($launchId, [
			'EXPIRES_AT' => $expiresAt,
		]);
	}

	/**
	 * Writes back what the start of the process gave the row: the created smart process item, the
	 * identifier the engine returned and the resulting state.
	 */
	public function updateItem(LaunchItem $item): Result
	{
		if ($item->id === null)
		{
			return (new Result())->addError(new Error('Launch item is not saved yet'));
		}

		return Internal\Process\LaunchItemTable::update($item->id, [
			'ENTITY_ID' => $item->entityId,
			'WORKFLOW_ID' => $item->workflowId,
			'STATUS' => $item->status->value,
			'ERROR_MESSAGE' => $item->errorMessage,
		]);
	}

	/**
	 * @param int[] $ids
	 *
	 * @return list<int>
	 */
	private function filterIds(array $ids): array
	{
		return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
	}

	private function extractLaunchFromRow(array $row): Launch
	{
		return new Launch(
			createdById: (int)$row['CREATED_BY'],
			templateId: (int)$row['TEMPLATE_ID'],
			entityTypeId: (int)$row['ENTITY_TYPE_ID'],
			memberCount: (int)$row['MEMBER_COUNT'],
			queuedAt: DateTime::createFromMainDateTime($row['QUEUED_AT']),
			expiresAt: DateTime::createFromMainDateTime($row['EXPIRES_AT']),
			status: LaunchStatus::from($row['STATUS']),
			parameters: $row['PARAMETERS'] ?? [],
			id: (int)$row['ID'],
		);
	}

	private function extractLaunchItemFromRow(array $row): LaunchItem
	{
		return new LaunchItem(
			launchId: (int)$row['LAUNCH_ID'],
			employeeId: (int)$row['EMPLOYEE_ID'],
			status: LaunchItemStatus::from($row['STATUS']),
			entityId: isset($row['ENTITY_ID']) ? (int)$row['ENTITY_ID'] : null,
			workflowId: $row['WORKFLOW_ID'] ?? null,
			errorMessage: $row['ERROR_MESSAGE'] ?? null,
			id: (int)$row['ID'],
		);
	}
}
