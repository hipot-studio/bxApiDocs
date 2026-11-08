<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Tab\Preset\Enum\AssignmentTargetType;
use Bitrix\Mobile\Tab\Preset\Model\PresetAssignmentTable;

final class PresetAssignmentService
{
	public function __construct(
		private readonly PresetAccessPolicy $accessPolicy = new PresetAccessPolicy(),
		private readonly MobileMenuCacheInvalidator $cacheInvalidator = new MobileMenuCacheInvalidator(),
		private readonly PresetWriteTransaction $transaction = new PresetWriteTransaction(),
	)
	{
	}

	public function refreshByPresetId(int $presetId): void
	{
		$targetsByAssignerId = [];
		$result = PresetAssignmentTable::getList([
			'select' => ['ASSIGNER_ID', 'TARGET_TYPE', 'TARGET_ID'],
			'filter' => [
				'=PRESET_ID' => $presetId,
				'=IS_ACTIVE' => 'Y',
			],
		]);
		while ($row = $result->fetch())
		{
			$targetType = $this->normalizeAssignmentTargetType((string)$row['TARGET_TYPE']);
			$targetId = (int)$row['TARGET_ID'];
			$assignerId = (int)$row['ASSIGNER_ID'];
			if ($assignerId <= 0 || $targetId <= 0)
			{
				continue;
			}

			$targetsByAssignerId[$assignerId][$targetType->value . ':' . $targetId] = [$targetType, $targetId];
		}

		if (empty($targetsByAssignerId))
		{
			return;
		}

		$affectedUserIds = $this->transaction->run(function () use ($presetId, $targetsByAssignerId): array {
			$affectedUserIds = $this->deactivateActiveAssignments($presetId);
			foreach ($targetsByAssignerId as $assignerId => $targets)
			{
				$this->insertAssignments($assignerId, $presetId, $targets);
				$affectedUserIds = array_merge($affectedUserIds, $this->collectAffectedUserIds($assignerId, $targets));
			}

			return $affectedUserIds;
		});
		$this->transaction->afterCommit(function () use ($affectedUserIds): void {
			$this->cacheInvalidator->clearUsers($affectedUserIds);
		});
	}

	private function insertAssignments(int $assignerId, int $presetId, array $targets): int
	{
		$maxVersion = 0;
		foreach ($targets as [$targetType, $targetId])
		{
			$now = new DateTime();
			$result = PresetAssignmentTable::add([
				'PRESET_ID' => $presetId,
				'ASSIGNER_ID' => $assignerId,
				'TARGET_TYPE' => $targetType->value,
				'TARGET_ID' => $targetId,
				'VERSION' => 0,
				'IS_ACTIVE' => 'Y',
				'CREATED_AT' => $now,
				'UPDATED_AT' => $now,
			]);
			if (!$result->isSuccess())
			{
				throw new SystemException(implode('; ', $result->getErrorMessages()), 422);
			}

			$version = (int)$result->getId();
			PresetAssignmentTable::update($version, [
				'VERSION' => $version,
				'UPDATED_AT' => $now,
			]);
			$maxVersion = max($maxVersion, $version);
		}

		return $maxVersion;
	}

	public function deactivateByPresetId(int $presetId): void
	{
		$affectedUserIds = $this->transaction->run(fn(): array => $this->deactivateActiveAssignments($presetId));
		$this->transaction->afterCommit(function () use ($affectedUserIds): void {
			$this->cacheInvalidator->clearUsers($affectedUserIds);
		});
	}

	private function deactivateActiveAssignments(int $presetId): array
	{
		$affectedUserIds = [];
		$result = PresetAssignmentTable::getList([
			'select' => ['ID', 'ASSIGNER_ID', 'TARGET_TYPE', 'TARGET_ID'],
			'filter' => [
				'=PRESET_ID' => $presetId,
				'=IS_ACTIVE' => 'Y',
			],
		]);
		while ($row = $result->fetch())
		{
			$affectedUserIds = array_merge($affectedUserIds, $this->collectAffectedUserIds(
				(int)$row['ASSIGNER_ID'],
				[[$this->normalizeAssignmentTargetType((string)$row['TARGET_TYPE']), (int)$row['TARGET_ID']]],
			));

			PresetAssignmentTable::update((int)$row['ID'], [
				'IS_ACTIVE' => 'N',
				'UPDATED_AT' => new DateTime(),
			]);
		}

		return $affectedUserIds;
	}

	private function normalizeAssignmentTargetType(string $type): AssignmentTargetType
	{
		return AssignmentTargetType::tryFrom($type) ?? AssignmentTargetType::User;
	}

	private function collectAffectedUserIds(int $assignerId, array $targets): array
	{
		$userIds = [];
		foreach ($targets as [$targetType, $targetId])
		{
			if ($targetType === AssignmentTargetType::User)
			{
				$userIds[] = $targetId;
			}
			else
			{
				$userIds = array_merge(
					$userIds,
					$this->accessPolicy->getAssignableUserIdsByDepartment($assignerId, $targetId),
				);
			}
		}

		$userIds = array_map('intval', $userIds);
		$userIds = array_filter($userIds, static fn(int $userId): bool => $userId > 0);

		return array_values(array_unique($userIds));
	}

}
