<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row;

use Bitrix\Main\Grid\Row\Action\DataProvider;
use Bitrix\Main\Grid\Row\Rows;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Column\DataProvider\ReplicationProvider;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\Assembler\ReplicationAssembler;

class ReplicationRows extends Rows
{
	public const GROUP_DATA = 'SECTION_GROUP';

	/**
	 * @param array<int, array{key: string, label: string, sub: string, isNow: bool}> $segments
	 * @param string[] $visibleColumnIds
	 * @param DataProvider ...$actionsProviders row menu item providers (P1.T1)
	 */
	public function __construct(
		array $segments = [],
		array $visibleColumnIds = [],
		DataProvider ...$actionsProviders,
	)
	{
		$periodColumnIds = array_map(
			static fn (array $segment): string => ReplicationProvider::periodColumnId((string)$segment['key']),
			$segments,
		);

		$visibleColumnIds = $visibleColumnIds === [] ? [
			ReplicationProvider::NAME_COLUMN,
			ReplicationProvider::ASSIGNEE_COLUMN,
			ReplicationProvider::CONDITION_COLUMN,
			ReplicationProvider::DEADLINE_COLUMN,
			ReplicationProvider::ORIGINATOR_NAME_COLUMN,
			ReplicationProvider::GROUP_NAME_COLUMN,
			ReplicationProvider::ID_COLUMN,
			ReplicationProvider::AUDITOR_COLUMN,
			ReplicationProvider::ACCOMPLICE_COLUMN,
			...$periodColumnIds,
		] : $visibleColumnIds;

		parent::__construct(new ReplicationAssembler($visibleColumnIds, $periodColumnIds), ...$actionsProviders);
	}

	public function prepareRows(iterable $rawRows): array
	{
		$rows = [];
		$groups = [];

		foreach ($rawRows as $rawRow)
		{
			$groups[] = $rawRow[self::GROUP_DATA] ?? null;
			unset($rawRow[self::GROUP_DATA]);

			$rows[] = $rawRow;
		}

		$preparedRows = parent::prepareRows($rows);
		$result = [];
		$previousGroupKey = null;

		foreach ($preparedRows as $index => $preparedRow)
		{
			$group = $groups[$index] ?? null;
			if (
				$this->isGroupData($group)
				&& $group['key'] !== $previousGroupKey
			)
			{
				$result[] = $this->prepareGroupRow($group);
				$previousGroupKey = $group['key'];
			}

			$result[] = $preparedRow;
		}

		return $result;
	}

	private function isGroupData(mixed $group): bool
	{
		return
			is_array($group)
			&& isset($group['key'], $group['type'], $group['label'])
			&& array_key_exists('entityId', $group)
			&& is_string($group['key'])
			&& $group['key'] !== ''
			&& $group['type'] === 'project'
			&& is_string($group['label'])
		;
	}

	/**
	 * @param array{key: string, type: string, entityId: int|string|null, label: string} $group
	 */
	private function prepareGroupRow(array $group): array
	{
		return [
			'id' => 'replication_group_' . md5($group['key']),
			'not_count' => true,
			'draggable' => false,
			'custom' =>
				'<div class="tasks-replication-grid-group">'
				. htmlspecialcharsbx($group['label'])
				. '</div>'
			,
			'attrs' => [
				'data-type' => 'group',
				'data-group-key' => $group['key'],
				'data-group-type' => $group['type'],
			],
		];
	}
}
