<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use Bitrix\Mobile\Tab\Manager;
use Bitrix\Mobile\Tab\Preset\Dto\PresetReference;
use Bitrix\Mobile\Tab\Preset\Enum\AssignmentTargetType;
use Bitrix\Mobile\Tab\Preset\Model\ManagedPresetTable;
use Bitrix\Mobile\Tab\Preset\Model\PresetAssignmentV2Table;

final class PresetAssignmentV2Service
{
	public const ERROR_RECIPIENT_NOT_ALLOWED = 'RECIPIENT_NOT_ALLOWED';
	public const ERROR_MESSAGE_RECIPIENT_NOT_ALLOWED = 'Recipient is not allowed';
	public const ERROR_MESSAGE_HR_UNAVAILABLE = 'Human resources module is unavailable';

	private const DEPARTMENT_SCOPE_FLAT = 'flat';
	private const DEPARTMENT_SCOPE_RECURSIVE = 'recursive';
	private const LATEST_ASSIGNMENT_LOOKUP_LIMIT = 50;

	private readonly TabPresetConfigProvider $configProvider;
	private ?ManagedPresetService $managedPresetService = null;
	private array $managedPresetAssignmentAccess = [];

	public function __construct(
		private readonly Manager $manager,
		private readonly PresetAccessPolicy $accessPolicy = new PresetAccessPolicy(),
		?TabPresetConfigProvider $configProvider = null,
		private readonly MobileMenuCacheInvalidator $cacheInvalidator = new MobileMenuCacheInvalidator(),
		private readonly PresetWriteTransaction $transaction = new PresetWriteTransaction(),
	)
	{
		$this->configProvider = $configProvider ?? new TabPresetConfigProvider($this->manager);
	}

	/**
	 * @throws SystemException
	 */
	public function assign(
		int $assignerId,
		PresetReference $reference,
		array $recipients,
		?array $configOverride = null,
	): int
	{
		$this->ensureCanManage($assignerId);
		if (!$reference->isAssignable())
		{
			throw new SystemException('Preset source is not assignable', 422);
		}
		$this->ensureCanAssignPreset($assignerId, $reference);

		$config = $this->getAssignmentConfig($reference, $configOverride);
		if ($config === null)
		{
			throw new SystemException('Preset not found', 404);
		}

		$targets = [];
		foreach ($recipients as $recipient)
		{
			$rawTargetType = (string)($recipient['type'] ?? '');
			$rawTargetId = (int)($recipient['id'] ?? 0);
			$targetType = $this->mapTargetType($rawTargetType, (string)($recipient['scope'] ?? ''));
			$targetId = $targetType->isDepartment()
				? $this->accessPolicy->resolveDepartmentIdByLegacyId($rawTargetId)
				: $rawTargetId;
			if ($targetId <= 0 || !$this->accessPolicy->canAssignToTarget($assignerId, $targetType, $targetId))
			{
				throw new SystemException(self::ERROR_MESSAGE_RECIPIENT_NOT_ALLOWED, 422);
			}

			$targets[$this->getTargetKey($targetType, $targetId)] = [$targetType, $targetId];
		}

		[$version, $affectedUserIds] = $this->transaction->run(function () use (
			$assignerId,
			$reference,
			$config,
			$targets,
		): array {
			$affectedUserIds = $this->deactivateActiveAssignments($reference, assignerId: $assignerId);
			$version = $this->insertAssignments($assignerId, $reference, $config, $targets);
			$affectedUserIds = array_merge($affectedUserIds, $this->collectAffectedUserIds($assignerId, $targets));

			return [$version, $affectedUserIds];
		});
		$this->transaction->afterCommit(function () use ($affectedUserIds): void {
			$this->cacheInvalidator->clearUsers($affectedUserIds);
		});

		return $version;
	}

	public function refreshManagedPreset(int $presetId): void
	{
		if ($presetId <= 0)
		{
			return;
		}

		$reference = PresetReference::fromPresetId('managed:' . $presetId);
		$config = $this->getPresetConfig($reference);
		if ($config === null)
		{
			return;
		}

		$targetsByAssignerId = [];
		$result = PresetAssignmentV2Table::getList([
			'select' => ['ASSIGNER_ID', 'TARGET_TYPE', 'TARGET_ID'],
			'filter' => [
				'=PRESET_SOURCE' => $reference->source->value,
				'=PRESET_KEY' => $reference->key,
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

			$targetsByAssignerId[$assignerId][$this->getTargetKey($targetType, $targetId)] = [$targetType, $targetId];
		}

		if (empty($targetsByAssignerId))
		{
			return;
		}

		$affectedUserIds = $this->transaction->run(function () use ($reference, $config, $targetsByAssignerId): array {
			$affectedUserIds = $this->deactivateActiveAssignments($reference);
			foreach ($targetsByAssignerId as $assignerId => $targets)
			{
				$this->insertAssignments($assignerId, $reference, $config, $targets);
				$affectedUserIds = array_merge($affectedUserIds, $this->collectAffectedUserIds($assignerId, $targets));
			}

			return $affectedUserIds;
		});
		$this->transaction->afterCommit(function () use ($affectedUserIds): void {
			$this->cacheInvalidator->clearUsers($affectedUserIds);
		});
	}

	public function deactivateManagedPreset(int $presetId): void
	{
		if ($presetId <= 0)
		{
			return;
		}

		$affectedUserIds = $this->transaction->run(
			fn(): array => $this->deactivateActiveAssignments(PresetReference::fromPresetId('managed:' . $presetId)),
		);
		$this->transaction->afterCommit(function () use ($affectedUserIds): void {
			$this->cacheInvalidator->clearUsers($affectedUserIds);
		});
	}

	public function getLatestAssignment(int $userId): ?array
	{
		$departmentIds = $this->accessPolicy->getDepartmentIdsForAssignmentLookup($userId);
		$targetFilter = [
			[
				'=TARGET_TYPE' => AssignmentTargetType::User->value,
				'=TARGET_ID' => $userId,
			],
		];

		if (!empty($departmentIds))
		{
			$targetFilter[] = [
				'=TARGET_TYPE' => AssignmentTargetType::HrNode->value,
				'@TARGET_ID' => $departmentIds,
			];
		}
		$directDepartmentIds = $this->accessPolicy->getDirectDepartmentIdsForUser($userId);
		if (!empty($directDepartmentIds))
		{
			$targetFilter[] = [
				'=TARGET_TYPE' => AssignmentTargetType::HrNodeFlat->value,
				'@TARGET_ID' => $directDepartmentIds,
			];
		}

		$result = PresetAssignmentV2Table::getList([
			'select' => [
				'ID',
				'ASSIGNER_ID',
				'PRESET_SOURCE',
				'PRESET_KEY',
				'PRESET_CONFIG_JSON',
				'TARGET_TYPE',
				'TARGET_ID',
				'VERSION',
			],
			'filter' => [
				'=IS_ACTIVE' => 'Y',
				[
					'LOGIC' => 'OR',
					...$targetFilter,
				],
			],
			'order' => ['VERSION' => 'DESC', 'ID' => 'DESC'],
			'limit' => self::LATEST_ASSIGNMENT_LOOKUP_LIMIT,
		]);
		while ($row = $result->fetch())
		{
			if ($this->canApplyAssignment($row))
			{
				return $row;
			}
		}

		return null;
	}

	public function getVisibleActiveTargets(PresetReference $reference, int $viewerId): array
	{
		$managedPresetId = $reference->getManagedPresetId();
		if (
			$managedPresetId !== null
			&& !(new ManagedPresetService($this->manager, $this->accessPolicy))->isPresetOwner($viewerId, $managedPresetId)
		)
		{
			return [];
		}

		$referenceKey = $this->getReferenceKey($reference);
		$targetsByReference = $this->getActiveTargetsByReferences([$referenceKey => $reference], $viewerId);

		return $targetsByReference[$referenceKey] ?? [];
	}

	public function getActiveTargetsByReferences(array $referencesByPresetId, int $viewerId): array
	{
		if (empty($referencesByPresetId))
		{
			return [];
		}

		$targetsByPresetId = array_fill_keys(array_keys($referencesByPresetId), []);
		$presetIdByReferenceKey = [];
		$referenceFilter = Query::filter()->logic('or');
		foreach ($referencesByPresetId as $presetId => $reference)
		{
			if (!$reference instanceof PresetReference)
			{
				continue;
			}

			$referenceKey = $this->getReferenceKey($reference);
			$presetIdByReferenceKey[$referenceKey] = $presetId;
			$referenceFilter->where(
				Query::filter()
					->where('PRESET_SOURCE', $reference->source->value)
					->where('PRESET_KEY', $reference->key),
			);
		}

		if (empty($presetIdByReferenceKey))
		{
			return [];
		}

		$query = PresetAssignmentV2Table::query()
			->setSelect(['PRESET_SOURCE', 'PRESET_KEY', 'TARGET_TYPE', 'TARGET_ID'])
			->where('IS_ACTIVE', 'Y')
			->where($referenceFilter)
			->setOrder(['ID' => 'ASC'])
		;
		if (!$this->accessPolicy->canSetPortalDefault($viewerId))
		{
			$query->where('ASSIGNER_ID', $viewerId);
		}

		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$referenceKey = $this->getReferenceKeyFromRow($row);
			$presetId = $presetIdByReferenceKey[$referenceKey] ?? null;
			$targetType = $this->normalizeAssignmentTargetType((string)$row['TARGET_TYPE']);
			$targetId = (int)$row['TARGET_ID'];
			if (
				$presetId === null
				|| $targetId <= 0
				|| !$this->accessPolicy->canAssignToTarget($viewerId, $targetType, $targetId)
			)
			{
				continue;
			}

			$targetsByPresetId[$presetId][$this->getTargetKey($targetType, $targetId)] = [
				'type' => $targetType,
				'id' => $targetId,
			];
		}

		return array_map('array_values', $targetsByPresetId);
	}

	public function getPresetConfig(PresetReference $reference): ?array
	{
		if ($reference->source->value === 'builtin')
		{
			return $this->configProvider->getPresetConfigByName($reference->key);
		}

		$presetId = $reference->getManagedPresetId();
		if ($presetId === null)
		{
			return null;
		}

		$row = ManagedPresetTable::getList([
			'filter' => [
				'=ID' => $presetId,
				'=IS_DELETED' => 'N',
			],
			'limit' => 1,
		])->fetch();
		if (!is_array($row))
		{
			return null;
		}

		try
		{
			$config = Json::decode((string)$row['CONFIG_JSON']);
		}
		catch (\Throwable)
		{
			return null;
		}

		return is_array($config) ? $this->configProvider->normalizePresetConfig($config) : null;
	}

	private function getAssignmentConfig(PresetReference $reference, ?array $configOverride): ?array
	{
		if ($configOverride !== null)
		{
			try
			{
				return (new TabConfigValidator($this->manager))->normalize($configOverride);
			}
			catch (ArgumentException $exception)
			{
				throw new SystemException($exception->getMessage(), 422);
			}
		}

		return $this->getPresetConfig($reference);
	}

	private function ensureCanAssignPreset(int $assignerId, PresetReference $reference): void
	{
		$presetId = $reference->getManagedPresetId();
		if ($presetId === null)
		{
			return;
		}

		if (!$this->getManagedPresetService()->canAssignPreset($assignerId, $presetId))
		{
			throw new SystemException('Access denied', 403);
		}
	}

	private function canApplyAssignment(array $assignment): bool
	{
		$assignerId = (int)($assignment['ASSIGNER_ID'] ?? 0);
		$targetType = $this->normalizeAssignmentTargetType((string)($assignment['TARGET_TYPE'] ?? ''));
		$targetId = (int)($assignment['TARGET_ID'] ?? 0);
		if (!$this->accessPolicy->canAssignToTarget($assignerId, $targetType, $targetId))
		{
			return false;
		}

		try
		{
			$reference = PresetReference::fromAssignmentRow($assignment);
		}
		catch (\Throwable)
		{
			return false;
		}

		$presetId = $reference->getManagedPresetId();
		if ($presetId === null)
		{
			return true;
		}

		return $this->canAssignerUseManagedPreset($assignerId, $presetId);
	}

	private function canAssignerUseManagedPreset(int $assignerId, int $presetId): bool
	{
		$cacheKey = $assignerId . ':' . $presetId;
		if (!array_key_exists($cacheKey, $this->managedPresetAssignmentAccess))
		{
			$this->managedPresetAssignmentAccess[$cacheKey] = $this->getManagedPresetService()
				->canAssignPreset($assignerId, $presetId)
			;
		}

		return $this->managedPresetAssignmentAccess[$cacheKey];
	}

	private function getManagedPresetService(): ManagedPresetService
	{
		$this->managedPresetService ??= new ManagedPresetService($this->manager, $this->accessPolicy);

		return $this->managedPresetService;
	}

	private function insertAssignments(
		int $assignerId,
		PresetReference $reference,
		array $config,
		array $targets,
	): int
	{
		$maxVersion = 0;
		$configJson = Json::encode($this->configProvider->normalizePresetConfig($config));
		foreach ($targets as [$targetType, $targetId])
		{
			$now = new DateTime();
			$result = PresetAssignmentV2Table::add([
				'ASSIGNER_ID' => $assignerId,
				'PRESET_SOURCE' => $reference->source->value,
				'PRESET_KEY' => $reference->key,
				'PRESET_CONFIG_JSON' => $configJson,
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
			PresetAssignmentV2Table::update($version, [
				'VERSION' => $version,
				'UPDATED_AT' => $now,
			]);
			$maxVersion = max($maxVersion, $version);
		}

		return $maxVersion;
	}

	private function deactivateActiveAssignments(
		PresetReference $reference,
		?array $targets = null,
		?int $assignerId = null,
	): array
	{
		$affectedUserIds = [];
		$targetKeys = null;
		if ($targets !== null)
		{
			$targetKeys = [];
			foreach ($targets as [$targetType, $targetId])
			{
				$targetKeys[$this->getTargetKey($targetType, $targetId)] = true;
			}
		}

		$filter = [
			'=PRESET_SOURCE' => $reference->source->value,
			'=PRESET_KEY' => $reference->key,
			'=IS_ACTIVE' => 'Y',
		];
		if ($assignerId !== null)
		{
			$filter['=ASSIGNER_ID'] = $assignerId;
		}

		$result = PresetAssignmentV2Table::getList([
			'select' => ['ID', 'ASSIGNER_ID', 'TARGET_TYPE', 'TARGET_ID'],
			'filter' => $filter,
		]);
		while ($row = $result->fetch())
		{
			$targetType = $this->normalizeAssignmentTargetType((string)$row['TARGET_TYPE']);
			$targetId = (int)$row['TARGET_ID'];
			if ($targetKeys !== null && !isset($targetKeys[$this->getTargetKey($targetType, $targetId)]))
			{
				continue;
			}

			$affectedUserIds = array_merge($affectedUserIds, $this->collectAffectedUserIds(
				(int)$row['ASSIGNER_ID'],
				[[$targetType, $targetId]],
			));

			PresetAssignmentV2Table::update((int)$row['ID'], [
				'IS_ACTIVE' => 'N',
				'UPDATED_AT' => new DateTime(),
			]);
		}

		return $affectedUserIds;
	}

	private function ensureCanManage(int $userId): void
	{
		if (!$this->accessPolicy->isHumanResourcesAvailable())
		{
			throw new SystemException(self::ERROR_MESSAGE_HR_UNAVAILABLE, 422);
		}

		if (!$this->accessPolicy->canManagePresets($userId))
		{
			throw new SystemException('Access denied', 403);
		}
	}

	private function mapTargetType(string $type, string $scope = ''): AssignmentTargetType
	{
		return match ($type)
		{
			'user' => AssignmentTargetType::User,
			'department' => $this->mapDepartmentTargetType($scope),
			default => throw new SystemException('Recipient type is invalid', 422),
		};
	}

	private function mapDepartmentTargetType(string $scope): AssignmentTargetType
	{
		$scope = $scope === '' ? self::DEPARTMENT_SCOPE_RECURSIVE : $scope;

		return match ($scope)
		{
			self::DEPARTMENT_SCOPE_FLAT => AssignmentTargetType::HrNodeFlat,
			self::DEPARTMENT_SCOPE_RECURSIVE => AssignmentTargetType::HrNode,
			default => throw new SystemException('Recipient scope is invalid', 422),
		};
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

				continue;
			}

			if ($targetType->isDepartment())
			{
				$userIds = array_merge(
					$userIds,
					$this->accessPolicy->getAssignableUserIdsByDepartment(
						$assignerId,
						$targetId,
						$targetType->isRecursiveDepartment(),
					),
				);
			}
		}

		$userIds = array_map('intval', $userIds);
		$userIds = array_filter($userIds, static fn(int $userId): bool => $userId > 0);

		return array_values(array_unique($userIds));
	}

	private function getTargetKey(AssignmentTargetType $targetType, int $targetId): string
	{
		return $targetType->value . ':' . $targetId;
	}

	private function getReferenceKey(PresetReference $reference): string
	{
		return $reference->source->value . ':' . $reference->key;
	}

	private function getReferenceKeyFromRow(array $row): string
	{
		return (string)$row['PRESET_SOURCE'] . ':' . (string)$row['PRESET_KEY'];
	}
}
