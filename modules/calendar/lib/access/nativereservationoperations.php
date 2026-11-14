<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Access;

use Bitrix\Calendar\Access\Model\SectionModel;
use Bitrix\Calendar\Access\Model\TypeModel;
use Bitrix\Calendar\Internals\AccessTable;
use Bitrix\Calendar\Util;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\TaskOperationTable;
use Bitrix\Main\TaskTable;
use LogicException;

final class NativeReservationOperations
{
	private const QUERY_CHUNK_SIZE = 500;

	private ?array $accessCodes = null;
	private array $permissions = [];
	private array $taskOperations = [];
	private array $tasks = [];
	private int $deniedTaskId = 0;

	public function __construct(
		private readonly int $userId,
		private readonly array $rooms,
	)
	{
	}

	public function assertSection(SectionModel $section, int $userId): void
	{
		$this->assertUser($userId);
		$room = $this->rooms[$section->getId()] ?? null;
		if ($room === null || $section->getType() !== (string)$room['CAL_TYPE']
			|| $section->getOwnerId() !== (int)$room['OWNER_ID'])
		{
			throw new LogicException('Reservation section scope changed.');
		}
	}

	public function assertType(TypeModel $type, int $userId): void
	{
		$this->assertUser($userId);
		if ($type->getXmlId() !== 'location')
		{
			throw new LogicException('Reservation calendar type changed.');
		}
	}

	public function getSectionOperations(SectionModel $section, int $userId): array
	{
		$this->assertSection($section, $userId);
		$this->load();

		return \CCalendarSect::collectPermissionOperations(
			$this->permissions[$section->getId()] ?? [],
			$this->accessCodes,
			fn($taskId) => $this->taskOperations[(int)$taskId] ?? [],
		);
	}

	public function getTypeOperations(TypeModel $type, int $userId): array
	{
		$this->assertType($type, $userId);
		$this->load();
		$permissions = $this->permissions[$type->getXmlId()] ?? [];
		$personalGrant = \CCalendarType::getLocationPersonalGrantDecision(
			$type->getXmlId(), $userId, $this->accessCodes, $permissions,
			fn() => $this->deniedTaskId,
			fn(int $taskId) => $this->isValidLocationGrantTask($taskId),
		);
		if ($personalGrant !== null && !$personalGrant['allowed'])
		{
			return [];
		}

		return \CCalendarSect::collectPermissionOperations(
			$permissions, $this->accessCodes, fn($taskId) => $this->taskOperations[(int)$taskId] ?? [],
		);
	}

	private function assertUser(int $userId): void
	{
		$userScopeChanged = $userId !== $this->userId;
		if (!$userScopeChanged && $this->accessCodes !== null)
		{
			$currentAccessCodes = Util::getUserAccessCodes($userId);
			$userScopeChanged = array_diff($this->accessCodes, $currentAccessCodes) !== []
				|| array_diff($currentAccessCodes, $this->accessCodes) !== []
			;
		}
		if ($userScopeChanged)
		{
			throw new LogicException('Reservation permission subject changed.');
		}
	}

	private function load(): void
	{
		if ($this->accessCodes !== null)
		{
			return;
		}
		$this->accessCodes = Util::getUserAccessCodes($this->userId);
		$sectionIds = array_merge(['location'], array_map('strval', array_keys($this->rooms)));
		foreach (array_chunk($sectionIds, self::QUERY_CHUNK_SIZE) as $sectionChunk)
		{
			foreach (array_chunk($this->accessCodes, self::QUERY_CHUNK_SIZE) as $codeChunk)
			{
				$rows = AccessTable::getList([
					'select' => ['SECT_ID', 'ACCESS_CODE', 'TASK_ID'],
					'filter' => ['@SECT_ID' => $sectionChunk, '@ACCESS_CODE' => $codeChunk],
				]);
				while ($row = $rows->fetch())
				{
					if ($row['SECT_ID'] === 'location'
						|| ($row['ACCESS_CODE'] !== '0' && (int)$row['TASK_ID'] > 0))
					{
						$this->permissions[$row['SECT_ID']][$row['ACCESS_CODE']] = (int)$row['TASK_ID'];
					}
				}
			}
		}
		$personalTaskId = (int)($this->permissions['location']['U' . $this->userId] ?? 0);
		$taskFilter = [
			'LOGIC' => 'OR',
			['=MODULE_ID' => 'calendar', '=BINDING' => 'calendar_type', '=NAME' => 'calendar_type_denied'],
			['=MODULE_ID' => 'calendar', '=BINDING' => 'calendar_section', '=NAME' => 'calendar_access'],
		];
		if ($personalTaskId > 0)
		{
			$taskFilter[] = ['=ID' => $personalTaskId];
		}
		$accessTaskId = false;
		$rows = TaskTable::getList([
			'select' => ['ID', 'NAME', 'LETTER', 'IS_TYPE_TASK', 'IS_SECTION_TASK'],
			'runtime' => [
				new ExpressionField('IS_TYPE_TASK',
					"CASE WHEN %s = 'calendar' AND %s = 'calendar_type' THEN 1 ELSE 0 END",
					['MODULE_ID', 'BINDING'],
				),
				new ExpressionField('IS_SECTION_TASK',
					"CASE WHEN %s = 'calendar' AND %s = 'calendar_section' THEN 1 ELSE 0 END",
					['MODULE_ID', 'BINDING'],
				),
			],
			'filter' => $taskFilter,
			'order' => ['ID' => 'ASC'],
		]);
		while ($task = $rows->fetch())
		{
			$taskId = (int)$task['ID'];
			$this->tasks[$taskId] = $task;
			if ((int)$task['IS_TYPE_TASK'] === 1
				&& $task['NAME'] === 'calendar_type_denied' && $this->deniedTaskId === 0)
			{
				$this->deniedTaskId = $taskId;
			}
			if ((int)$task['IS_SECTION_TASK'] === 1
				&& $task['NAME'] === 'calendar_access' && $accessTaskId === false)
			{
				$accessTaskId = $taskId;
			}
		}
		foreach ($this->rooms as $roomId => $room)
		{
			$this->permissions[$roomId] = \CCalendarSect::addOwnerPermission(
				$this->permissions[$roomId] ?? [], (string)$room['CAL_TYPE'], (int)$room['OWNER_ID'], $accessTaskId,
			);
		}
		$taskIds = [];
		foreach ($this->permissions as $permissions)
		{
			foreach ($permissions as $code => $taskId)
			{
				if ((int)$taskId > 0 && in_array($code, $this->accessCodes, true))
				{
					$taskIds[(int)$taskId] = true;
				}
			}
		}
		foreach (array_chunk(array_keys($taskIds), self::QUERY_CHUNK_SIZE) as $taskChunk)
		{
			$rows = TaskOperationTable::getList([
				'select' => ['TASK_ID', 'NAME' => 'OPERATION.NAME'],
				'filter' => ['@TASK_ID' => $taskChunk],
			]);
			while ($row = $rows->fetch())
			{
				$this->taskOperations[(int)$row['TASK_ID']][] = (string)$row['NAME'];
			}
		}
	}

	private function isValidLocationGrantTask(int $taskId): bool
	{
		$task = $this->tasks[$taskId] ?? null;

		return $task !== null && (int)$task['IS_TYPE_TASK'] === 1
			&& $task['NAME'] !== 'calendar_type_denied' && $task['LETTER'] !== 'D'
			&& ($this->taskOperations[$taskId] ?? []) !== [];
	}
}
