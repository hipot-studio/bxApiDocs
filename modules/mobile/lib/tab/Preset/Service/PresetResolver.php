<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\Main\Web\Json;
use Bitrix\Mobile\Tab\Manager;
use Bitrix\Mobile\Tab\Preset\Dto\PresetReference;
use Bitrix\Mobile\Tab\Preset\Dto\ResolvedTabPreset;
use Bitrix\Mobile\Tab\Preset\Enum\AssignmentTargetType;
use Bitrix\Mobile\Tab\Preset\Enum\PresetSource;
use Bitrix\Mobile\Tab\Preset\Model\ManagedPresetTable;
use Bitrix\Mobile\Tab\Preset\Model\PresetAssignmentTable;

final class PresetResolver
{
	private readonly TabPresetConfigProvider $configProvider;
	private readonly PresetAssignmentV2Service $assignmentV2Service;
	private readonly \Closure $legacyStateLoader;
	private readonly \Closure $latestAssignmentLoader;

	public function __construct(
		private readonly Manager $manager,
		private readonly PresetAccessPolicy $accessPolicy = new PresetAccessPolicy(),
		private readonly UserPresetStateService $stateService = new UserPresetStateService(),
		?TabPresetConfigProvider $configProvider = null,
		private readonly MobileMenuCacheInvalidator $cacheInvalidator = new MobileMenuCacheInvalidator(),
		?PresetAssignmentV2Service $assignmentV2Service = null,
		?LegacyUserPresetStateReader $legacyStateReader = null,
		?\Closure $legacyStateLoader = null,
		?\Closure $latestAssignmentLoader = null,
	)
	{
		$this->configProvider = $configProvider ?? new TabPresetConfigProvider($this->manager);
		$this->assignmentV2Service = $assignmentV2Service
			?? new PresetAssignmentV2Service($this->manager, $this->accessPolicy, $this->configProvider);
		$legacyStateReader ??= new LegacyUserPresetStateReader($this->configProvider);
		$this->legacyStateLoader = $legacyStateLoader ?? $legacyStateReader->read(...);
		$this->latestAssignmentLoader = $latestAssignmentLoader ?? $this->getLatestAssignment(...);
	}

	public function resolveManagedConfig(int $userId, string $siteId): ?array
	{
		return $this->resolveManagedPreset($userId, $siteId)?->config;
	}

	public function resolveManagedPreset(int $userId, string $siteId): ?ResolvedTabPreset
	{
		$state = $this->stateService->getState($userId, $siteId);
		if ($state === null)
		{
			$legacyState = ($this->legacyStateLoader)($userId, $siteId);
			$state = $this->importLegacyStateIfPresent($userId, $siteId, $legacyState);
		}

		$latestAssignment = ($this->latestAssignmentLoader)($userId);
		$lastSeenVersion = (int)($state['LAST_ASSIGNMENT_VERSION'] ?? 0);

		if ($latestAssignment && $this->shouldApplyAssignment($state, $latestAssignment))
		{
			[$config, $shouldMarkAssignmentAsDeleted] = $this->resolveAssignmentConfig($latestAssignment);
			if ($config !== null)
			{
				if ($this->saveAssignmentState($userId, $siteId, $latestAssignment, $config, $state))
				{
					$this->cacheInvalidator->clearUser($userId);

					return $this->createAssignmentResolvedPreset($latestAssignment, $config);
				}

				$state = $this->stateService->getState($userId, $siteId);

				return $this->getStoredStatePreset($state);
			}

			if ($shouldMarkAssignmentAsDeleted && (int)$latestAssignment['VERSION'] > $lastSeenVersion)
			{
				if (!$this->stateService->markDeletedAssignmentSeenIfNotManual(
					$userId,
					$siteId,
					(int)$latestAssignment['VERSION'],
					$state,
				))
				{
					$state = $this->stateService->getState($userId, $siteId);

					return $this->getStoredStatePreset($state);
				}

				$state = $this->stateService->getState($userId, $siteId);
			}
		}

		if (
			$state
			&& in_array(
				$state['CURRENT_SOURCE'],
				[
					PresetSource::Builtin->value,
					PresetSource::Intent->value,
					PresetSource::Manual->value,
					PresetSource::Managed->value,
					PresetSource::AppliedDeleted->value,
				],
				true,
			)
		)
		{
			$currentPresetId = (int)($state['CURRENT_PRESET_ID'] ?? 0);
			$isManuallySelected = !empty($state['MANUALLY_SET_AT']);
			$latestMatchesCurrentPreset = $this->assignmentMatchesCurrentManagedPreset(
				$latestAssignment,
				$currentPresetId,
			);
			$shouldResetManagedState = $state['CURRENT_SOURCE'] === PresetSource::Managed->value
				&& !$latestMatchesCurrentPreset
				&& !$this->isManagedPresetDeleted($currentPresetId);
			$hasStoredBuiltinState = !empty($state['CURRENT_BUILTIN_ID'])
				|| !empty($state['APPLIED_CONFIG_JSON']);
			$shouldResetBuiltinState = $state['CURRENT_SOURCE'] === PresetSource::Builtin->value
				&& $hasStoredBuiltinState
				&& !$this->assignmentMatchesState($latestAssignment ?? [], $state);
			if (!$isManuallySelected && ($shouldResetManagedState || $shouldResetBuiltinState))
			{
				if ($this->stateService->resetManagedStateIfNotManual($userId, $siteId, $state))
				{
					return null;
				}

				$state = $this->stateService->getState($userId, $siteId);

				return $this->getStoredStatePreset($state);
			}
		}

		return $this->getStoredStatePreset($state);
	}

	private function getStoredStatePreset(?array $state): ?ResolvedTabPreset
	{
		if (
			$state === null
			|| !in_array(
				$state['CURRENT_SOURCE'] ?? null,
				[
					PresetSource::Builtin->value,
					PresetSource::Intent->value,
					PresetSource::Manual->value,
					PresetSource::Managed->value,
					PresetSource::AppliedDeleted->value,
				],
				true,
			)
		)
		{
			return null;
		}

		$config = $this->stateService->getAppliedConfig($state);
		if ($config === null)
		{
			return null;
		}

		return ResolvedTabPreset::fromState(
			$this->configProvider->normalizePresetConfig($config),
			$state,
		);
	}

	private function createAssignmentResolvedPreset(array $assignment, array $config): ?ResolvedTabPreset
	{
		$config = $this->configProvider->normalizePresetConfig($config);
		if (!isset($assignment['PRESET_SOURCE']))
		{
			$presetId = (int)($assignment['PRESET_ID'] ?? 0);

			return $presetId > 0
				? new ResolvedTabPreset($config, PresetSource::Managed, 'managed:' . $presetId)
				: null;
		}

		try
		{
			$reference = PresetReference::fromAssignmentRow($assignment);
		}
		catch (\Throwable)
		{
			return null;
		}

		return new ResolvedTabPreset($config, $reference->source, $reference->key);
	}

	private function importLegacyStateIfPresent(int $userId, string $siteId, mixed $legacyState): ?array
	{
		if (!is_array($legacyState))
		{
			return null;
		}

		$config = $legacyState['config'] ?? null;
		if (!is_array($config) || empty($config))
		{
			return null;
		}

		$config = $this->configProvider->normalizePresetConfig($config);
		if (empty($config))
		{
			return null;
		}

		$isManual = ($legacyState['isManual'] ?? false) === true;
		if (($legacyState['kind'] ?? LegacyUserPresetStateReader::STATE_CUSTOM) === LegacyUserPresetStateReader::STATE_NAMED)
		{
			$presetId = (string)($legacyState['presetId'] ?? '');
			if ($presetId === '')
			{
				return null;
			}

			return $this->stateService->importLegacyBuiltinStateIfAbsent(
				$userId,
				$siteId,
				$presetId,
				$config,
				$isManual,
			);
		}

		if (($legacyState['kind'] ?? LegacyUserPresetStateReader::STATE_CUSTOM) !== LegacyUserPresetStateReader::STATE_CUSTOM)
		{
			return null;
		}

		return $this->stateService->importLegacyManualStateIfAbsent(
			$userId,
			$siteId,
			$config,
			$isManual,
		);
	}

	public function getLatestAssignment(int $userId): ?array
	{
		$v2Assignment = $this->assignmentV2Service->getLatestAssignment($userId);
		if ($v2Assignment !== null)
		{
			return $v2Assignment;
		}

		$departmentIds = $this->accessPolicy->getDirectDepartmentIdsForUser($userId);
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

		$row = PresetAssignmentTable::getList([
			'filter' => [
				'=IS_ACTIVE' => 'Y',
				[
					'LOGIC' => 'OR',
					...$targetFilter,
				],
			],
			'order' => ['VERSION' => 'DESC', 'ID' => 'DESC'],
			'limit' => 1,
		])->fetch();

		return is_array($row) ? $row : null;
	}

	public static function getManagedPresetIdFromAssignment(?array $assignment): int
	{
		if (empty($assignment))
		{
			return 0;
		}

		$legacyPresetId = (int)($assignment['PRESET_ID'] ?? 0);
		if ($legacyPresetId > 0)
		{
			return $legacyPresetId;
		}

		if (!isset($assignment['PRESET_SOURCE']))
		{
			return 0;
		}

		try
		{
			return PresetReference::fromAssignmentRow($assignment)->getManagedPresetId() ?? 0;
		}
		catch (\Throwable)
		{
			return 0;
		}
	}

	/**
	 * @return array{0: ?array, 1: bool}
	 */
	private function resolveAssignmentConfig(array $assignment): array
	{
		if (isset($assignment['PRESET_SOURCE']))
		{
			if (
				array_key_exists('PRESET_CONFIG_JSON', $assignment)
				&& $assignment['PRESET_CONFIG_JSON'] !== null
			)
			{
				return [$this->decodeConfig((string)$assignment['PRESET_CONFIG_JSON']), false];
			}

			try
			{
				$reference = PresetReference::fromAssignmentRow($assignment);
			}
			catch (\Throwable)
			{
				return [null, false];
			}

			$config = $this->assignmentV2Service->getPresetConfig($reference);
			if ($config !== null)
			{
				return [$config, false];
			}

			$managedPresetId = $reference->getManagedPresetId();
			$isMissing = $managedPresetId === null || $this->getManagedPreset($managedPresetId) === null;

			return [null, $isMissing];
		}

		$preset = $this->getManagedPreset((int)$assignment['PRESET_ID']);
		if ($preset === null)
		{
			return [null, true];
		}

		return [$this->decodeConfig((string)$preset['CONFIG_JSON']), false];
	}

	private function shouldApplyAssignment(?array $state, array $assignment): bool
	{
		if (!$state)
		{
			return true;
		}

		if (!empty($state['MANUALLY_SET_AT']))
		{
			return false;
		}

		$assignmentVersion = (int)($assignment['VERSION'] ?? 0);
		$lastSeenVersion = (int)($state['LAST_ASSIGNMENT_VERSION'] ?? 0);
		if ($assignmentVersion > $lastSeenVersion)
		{
			return true;
		}

		if ($assignmentVersion === $lastSeenVersion)
		{
			return false;
		}

		return !$this->assignmentMatchesState($assignment, $state);
	}

	private function saveAssignmentState(
		int $userId,
		string $siteId,
		array $assignment,
		array $config,
		?array $expectedState,
	): bool
	{
		$version = (int)$assignment['VERSION'];
		if (!isset($assignment['PRESET_SOURCE']))
		{
			return $this->stateService->saveManagedAssignmentStateIfNotManual(
				userId: $userId,
				siteId: $siteId,
				presetId: (int)$assignment['PRESET_ID'],
				config: $config,
				assignmentVersion: $version,
				expectedState: $expectedState,
			);
		}

		try
		{
			$reference = PresetReference::fromAssignmentRow($assignment);
		}
		catch (\Throwable)
		{
			return false;
		}

		if ($reference->source === PresetSource::Managed)
		{
			$presetId = $reference->getManagedPresetId();
			if ($presetId !== null)
			{
				return $this->stateService->saveManagedAssignmentStateIfNotManual(
					userId: $userId,
					siteId: $siteId,
					presetId: $presetId,
					config: $config,
					assignmentVersion: $version,
					expectedState: $expectedState,
				);
			}

			return false;
		}

		if ($reference->source === PresetSource::Builtin)
		{
			return $this->stateService->saveBuiltinAssignmentStateIfNotManual(
				userId: $userId,
				siteId: $siteId,
				presetId: $reference->key,
				config: $config,
				assignmentVersion: $version,
				expectedState: $expectedState,
			);
		}

		return false;
	}

	private function assignmentMatchesCurrentManagedPreset(?array $assignment, int $currentPresetId): bool
	{
		if (!$assignment || $currentPresetId <= 0)
		{
			return false;
		}

		if (!isset($assignment['PRESET_SOURCE']))
		{
			return (int)$assignment['PRESET_ID'] === $currentPresetId;
		}

		try
		{
			$reference = PresetReference::fromAssignmentRow($assignment);
		}
		catch (\Throwable)
		{
			return false;
		}

		return $reference->getManagedPresetId() === $currentPresetId;
	}

	private function assignmentMatchesState(array $assignment, array $state): bool
	{
		if (!isset($assignment['PRESET_SOURCE']))
		{
			return ($state['CURRENT_SOURCE'] ?? null) === PresetSource::Managed->value
				&& (int)($state['CURRENT_PRESET_ID'] ?? 0) === (int)($assignment['PRESET_ID'] ?? 0);
		}

		try
		{
			$reference = PresetReference::fromAssignmentRow($assignment);
		}
		catch (\Throwable)
		{
			return false;
		}

		if ($reference->source === PresetSource::Managed)
		{
			return ($state['CURRENT_SOURCE'] ?? null) === PresetSource::Managed->value
				&& (int)($state['CURRENT_PRESET_ID'] ?? 0) === $reference->getManagedPresetId();
		}

		if ($reference->source === PresetSource::Builtin)
		{
			return ($state['CURRENT_SOURCE'] ?? null) === PresetSource::Builtin->value
				&& (string)($state['CURRENT_BUILTIN_ID'] ?? '') === $reference->key;
		}

		return false;
	}

	private function getManagedPreset(int $presetId): ?array
	{
		$row = ManagedPresetTable::getList([
			'filter' => [
				'=ID' => $presetId,
				'=IS_DELETED' => 'N',
			],
			'limit' => 1,
		])->fetch();

		return is_array($row) ? $row : null;
	}

	private function isManagedPresetDeleted(int $presetId): bool
	{
		if ($presetId <= 0)
		{
			return false;
		}

		$row = ManagedPresetTable::getList([
			'select' => ['IS_DELETED'],
			'filter' => [
				'=ID' => $presetId,
			],
			'limit' => 1,
		])->fetch();

		return is_array($row) && ($row['IS_DELETED'] ?? 'N') === 'Y';
	}

	private function decodeConfig(string $config): ?array
	{
		try
		{
			$decoded = Json::decode($config);
		}
		catch (\Throwable)
		{
			return null;
		}

		return is_array($decoded) ? $decoded : null;
	}
}
