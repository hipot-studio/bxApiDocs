<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\Internal\Enum\Audit\AuditEventCode;

final class AuditRightsDiffBuilder
{
	public const MODE_INHERITED = 'inherited';
	public const MODE_CUSTOM = 'custom';

	public function buildDirectSubjectChanges(array $before, array $after): array
	{
		$oldSubjects = $this->groupDirectRights($before);
		$newSubjects = $this->groupDirectRights($after);
		$changes = [];
		foreach (array_unique(array_merge(array_keys($oldSubjects), array_keys($newSubjects))) as $accessCode)
		{
			$oldRights = $oldSubjects[$accessCode] ?? [];
			$newRights = $newSubjects[$accessCode] ?? [];
			$identity = static fn(array $right): array => [$right['domain'], $right['taskId'], $right['negative']];
			if (array_map($identity, $oldRights) === array_map($identity, $newRights))
			{
				continue;
			}
			$change = [
				'eventCode' => $oldRights === [] ? AuditEventCode::RightsSubjectAdded
					: ($newRights === [] ? AuditEventCode::RightsSubjectRemoved : AuditEventCode::RightsSubjectChanged),
				'accessCode' => $accessCode,
			];
			if (max(count($oldRights), count($newRights)) > 1)
			{
				$change['oldRights'] = $oldRights;
				$change['newRights'] = $newRights;
			}
			else
			{
				foreach (['old' => $oldRights, 'new' => $newRights] as $side => $rights)
				{
					if ($rights !== [])
					{
						$change[$side . 'TaskName'] = $rights[0]['taskName'];
						$change[$side . 'Domain'] = $rights[0]['domain'];
						$change[$side . 'Negative'] = $rights[0]['negative'];
					}
				}
			}
			$changes[] = $change;
		}

		return $changes;
	}

	private function groupDirectRights(array $snapshot): array
	{
		$subjects = [];
		foreach ($snapshot as $right)
		{
			$accessCode = (string)$right['accessCode'];
			$row = [
				'domain' => (string)($right['domain'] ?? ''),
				'taskId' => (int)$right['taskId'],
				'taskName' => (string)$right['taskName'],
				'negative' => (bool)$right['negative'],
			];
			$key = serialize([$row['domain'], $row['taskId'], $row['negative']]);
			$subjects[$accessCode][$key] = $row;
		}
		ksort($subjects);
		foreach ($subjects as &$rights)
		{
			$rights = array_values($rights);
			usort($rights, static fn(array $a, array $b): int => strcmp($a['domain'], $b['domain'])
				?: ($a['taskId'] <=> $b['taskId']) ?: ($a['negative'] <=> $b['negative']));
		}
		unset($rights);

		return $subjects;
	}

	/**
	 * @return array<int, array{
	 *     eventCode: AuditEventCode,
	 *     accessCode: string,
	 *     oldTaskName?: string,
	 *     newTaskName?: string
	 * }>
	 */
	public function buildSubjectChanges(array $oldMembersAccess, array $newMembersAccess): array
	{
		$oldView = $this->normalizeMembersAccess($oldMembersAccess);
		$newView = $this->normalizeMembersAccess($newMembersAccess);
		$changes = [];

		foreach ($newView as $accessCode => $newTaskName)
		{
			if (!isset($oldView[$accessCode]))
			{
				$changes[] = [
					'eventCode' => AuditEventCode::RightsSubjectAdded,
					'accessCode' => $accessCode,
					'newTaskName' => $newTaskName,
				];

				continue;
			}

			$oldTaskName = $oldView[$accessCode];
			if ($oldTaskName !== $newTaskName)
			{
				$changes[] = [
					'eventCode' => AuditEventCode::RightsSubjectChanged,
					'accessCode' => $accessCode,
					'oldTaskName' => $oldTaskName,
					'newTaskName' => $newTaskName,
				];
			}
		}

		foreach ($oldView as $accessCode => $oldTaskName)
		{
			if (!isset($newView[$accessCode]))
			{
				$changes[] = [
					'eventCode' => AuditEventCode::RightsSubjectRemoved,
					'accessCode' => $accessCode,
					'oldTaskName' => $oldTaskName,
				];
			}
		}

		return $changes;
	}

	/**
	 * @return array<int, array{
	 *     eventCode: AuditEventCode,
	 *     oldMode: string,
	 *     newMode: string
	 * }>
	 */
	public function buildInheritanceChanges(array $oldMembersAccess, array $newMembersAccess): array
	{
		$oldMode = $this->resolveInheritanceMode($oldMembersAccess);
		$newMode = $this->resolveInheritanceMode($newMembersAccess);

		if ($oldMode === $newMode)
		{
			return [];
		}

		$eventCode = $newMode === self::MODE_INHERITED
			? AuditEventCode::RightsInheritanceEnabled
			: AuditEventCode::RightsInheritanceDisabled;

		return [
			[
				'eventCode' => $eventCode,
				'oldMode' => $oldMode,
				'newMode' => $newMode,
			],
		];
	}

	/**
	 * @return null|array{
	 *     eventCode: AuditEventCode,
	 *     cascadeRootObjectId: int,
	 *     addedCount: int,
	 *     changedCount: int,
	 *     removedCount: int
	 * }
	 */
	public function buildCascadeChange(array $subjectChanges, int $cascadeRootObjectId): ?array
	{
		if ($subjectChanges === [])
		{
			return null;
		}

		$counts = [
			AuditEventCode::RightsSubjectAdded->value => 0,
			AuditEventCode::RightsSubjectChanged->value => 0,
			AuditEventCode::RightsSubjectRemoved->value => 0,
		];

		foreach ($subjectChanges as $subjectChange)
		{
			$eventCode = $subjectChange['eventCode'] ?? null;
			if (!$eventCode instanceof AuditEventCode || !isset($counts[$eventCode->value]))
			{
				continue;
			}

			$counts[$eventCode->value]++;
		}

		return [
			'eventCode' => AuditEventCode::RightsCascadeChanged,
			'cascadeRootObjectId' => $cascadeRootObjectId,
			'addedCount' => $counts[AuditEventCode::RightsSubjectAdded->value],
			'changedCount' => $counts[AuditEventCode::RightsSubjectChanged->value],
			'removedCount' => $counts[AuditEventCode::RightsSubjectRemoved->value],
		];
	}

	/**
	 * @return array<string, string>
	 */
	private function normalizeMembersAccess(array $membersAccess): array
	{
		$view = [];

		foreach ($membersAccess as $memberAccess)
		{
			if (!is_array($memberAccess))
			{
				continue;
			}

			$accessCode = (string)($memberAccess['entityId'] ?? $memberAccess['accessCode'] ?? '');
			$taskName = (string)($memberAccess['right'] ?? $memberAccess['taskName'] ?? '');

			if ($accessCode === '' || $taskName === '')
			{
				continue;
			}

			$view[$accessCode] = $taskName;
		}

		ksort($view);

		return $view;
	}

	private function resolveInheritanceMode(array $membersAccess): string
	{
		return $this->normalizeMembersAccess($membersAccess) === []
			? self::MODE_INHERITED
			: self::MODE_CUSTOM;
	}
}
