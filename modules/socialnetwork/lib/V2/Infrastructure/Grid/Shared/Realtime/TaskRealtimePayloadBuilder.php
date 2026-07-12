<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Realtime;

use Bitrix\Main\Grid;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Url\WorkgroupActionUrlProvider;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Url\WorkgroupViewUrlProvider;

class TaskRealtimePayloadBuilder
{
	/**
	 * @param int[] $targetIds
	 */
	public function build(
		TaskRealtimeGridRuntime $runtime,
		array $rows,
		array $targetIds,
		array $urlContext,
	): array
	{
		$this->fillViewUrlData($rows, $runtime->isScrum, $urlContext);
		$this->fillActionUrlData($rows, $urlContext);

		$preparedRows = $this->prepareGridRows($runtime->grid, $rows);

		return $this->buildRealtimePayload($preparedRows, $targetIds);
	}

	private function prepareGridRows(Grid\Grid $grid, array $rows): array
	{
		return $grid->getRows()->prepareRows($rows);
	}

	/**
	 * @param int[] $targetIds
	 */
	private function buildRealtimePayload(array $preparedRows, array $targetIds): array
	{
		$targetIds = array_values(array_unique(array_filter(
			array_map(static fn(mixed $id): int => (int)$id, $targetIds),
			static fn(int $id): bool => $id > 0,
		)));

		if (empty($targetIds))
		{
			return [];
		}

		$preparedRowsById = [];
		$pageRowIds = [];
		foreach ($preparedRows as $preparedRow)
		{
			$rowId = (int)($preparedRow['id'] ?? 0);
			if ($rowId <= 0)
			{
				continue;
			}

			$preparedRowsById[$rowId] = $preparedRow;
			$pageRowIds[] = $rowId;
		}

		$result = [];
		foreach ($targetIds as $targetId)
		{
			if (!isset($preparedRowsById[$targetId]))
			{
				$result[$targetId] = false;

				continue;
			}

			$position = array_search($targetId, $pageRowIds, true);
			if ($position === false)
			{
				$result[$targetId] = false;

				continue;
			}

			$result[$targetId] = [
				'row' => $this->extractRealtimeRow($preparedRowsById[$targetId]),
				'position' => [
					'rowBefore' => ($position > 0 ? $pageRowIds[$position - 1] : 0),
					'rowAfter' => (
						$position < (count($pageRowIds) - 1)
							? $pageRowIds[$position + 1]
							: 0
					),
				],
			];
		}

		return $result;
	}

	private function fillViewUrlData(array &$rows, bool $isScrum, array $urlContext): void
	{
		if (empty($rows))
		{
			return;
		}

		$urlContext = $this->normalizeUrlContext($urlContext);
		$provider = new WorkgroupViewUrlProvider(
			mode: $urlContext['mode'],
			pathToGroup: $urlContext['pathToGroup'],
			pathToGroupTasks: $urlContext['pathToGroupTasks'],
		);

		foreach ($rows as &$row)
		{
			$row['VIEW_URL'] = $provider->getUrl(
				groupId: (int)($row['ID'] ?? 0),
				groupType: $this->resolveRowType($row, $isScrum),
			);
		}
		unset($row);
	}

	private function fillActionUrlData(array &$rows, array $urlContext): void
	{
		if (empty($rows))
		{
			return;
		}

		$urlContext = $this->normalizeUrlContext($urlContext);
		$provider = new WorkgroupActionUrlProvider(
			pathToGroupEdit: $urlContext['pathToGroupEdit'],
			pathToGroupDelete: $urlContext['pathToGroupDelete'],
			pathToLeaveGroup: $urlContext['pathToLeaveGroup'],
		);

		foreach ($rows as &$row)
		{
			$id = (int)($row['ID'] ?? 0);
			$row['EDIT_URL'] = $provider->getEditUrl($id);
			$row['DELETE_URL'] = $provider->getDeleteUrl($id);
			$row['LEAVE_URL'] = $provider->getLeaveUrl($id);
		}
		unset($row);
	}

	private function extractRealtimeRow(array $preparedRow): array
	{
		$result = [
			'columns' => $preparedRow['columns'] ?? [],
			'actions' => $preparedRow['actions'] ?? [],
			'cellActions' => $preparedRow['cellActions'] ?? [],
		];

		if (isset($preparedRow['counters']) && is_array($preparedRow['counters']))
		{
			$result['counters'] = $preparedRow['counters'];
		}

		return $result;
	}

	private function resolveRowType(array $row, bool $isScrum): ?string
	{
		$type = $row['TYPE'] ?? null;
		if (is_string($type) && $type !== '')
		{
			return $type;
		}

		return ($isScrum ? Type::Scrum->value : null);
	}

	private function normalizeUrlContext(array $urlContext): array
	{
		return [
			'mode' => (string)($urlContext['mode'] ?? ''),
			'pathToGroup' => (string)($urlContext['pathToGroup'] ?? ''),
			'pathToGroupTasks' => (string)($urlContext['pathToGroupTasks'] ?? ''),
			'pathToGroupEdit' => (string)($urlContext['pathToGroupEdit'] ?? ''),
			'pathToGroupDelete' => (string)($urlContext['pathToGroupDelete'] ?? ''),
			'pathToLeaveGroup' => (string)($urlContext['pathToLeaveGroup'] ?? ''),
		];
	}
}
