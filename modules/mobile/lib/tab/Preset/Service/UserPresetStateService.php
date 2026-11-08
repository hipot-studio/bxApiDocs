<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlHelper;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use Bitrix\Mobile\Tab\Preset\Enum\PresetSource;
use Bitrix\Mobile\Tab\Preset\Model\UserPresetStateTable;

final class UserPresetStateService
{
	public function getState(int $userId, string $siteId): ?array
	{
		if ($userId <= 0 || $siteId === '')
		{
			return null;
		}

		$row = UserPresetStateTable::getByPrimary([
			'USER_ID' => $userId,
			'SITE_ID' => $siteId,
		])->fetch();

		return is_array($row) ? $row : null;
	}

	public function saveManagedState(
		int $userId,
		string $siteId,
		int $presetId,
		array $config,
		int $assignmentVersion,
		bool $isManual = false,
	): void
	{
		$this->upsert($userId, $siteId, [
			'CURRENT_SOURCE' => PresetSource::Managed->value,
			'CURRENT_PRESET_ID' => $presetId,
			'CURRENT_BUILTIN_ID' => null,
			'APPLIED_CONFIG_JSON' => Json::encode($config),
			'LAST_ASSIGNMENT_VERSION' => $assignmentVersion,
			'MANUALLY_SET_AT' => $isManual ? new DateTime() : null,
			'UPDATED_AT' => new DateTime(),
		]);
	}

	public function saveBuiltinState(int $userId, string $siteId, string $presetId): void
	{
		$state = $this->getState($userId, $siteId);

		$this->upsert($userId, $siteId, [
			'CURRENT_SOURCE' => PresetSource::Builtin->value,
			'CURRENT_PRESET_ID' => null,
			'CURRENT_BUILTIN_ID' => $presetId,
			'APPLIED_CONFIG_JSON' => null,
			'LAST_ASSIGNMENT_VERSION' => (int)($state['LAST_ASSIGNMENT_VERSION'] ?? 0),
			'MANUALLY_SET_AT' => new DateTime(),
			'UPDATED_AT' => new DateTime(),
		]);
	}

	public function saveAssignedBuiltinState(
		int $userId,
		string $siteId,
		string $presetId,
		array $config,
		int $assignmentVersion,
	): void
	{
		$this->upsert($userId, $siteId, [
			'CURRENT_SOURCE' => PresetSource::Builtin->value,
			'CURRENT_PRESET_ID' => null,
			'CURRENT_BUILTIN_ID' => $presetId,
			'APPLIED_CONFIG_JSON' => Json::encode($config),
			'LAST_ASSIGNMENT_VERSION' => $assignmentVersion,
			'MANUALLY_SET_AT' => null,
			'UPDATED_AT' => new DateTime(),
		]);
	}

	public function saveManagedAssignmentStateIfNotManual(
		int $userId,
		string $siteId,
		int $presetId,
		array $config,
		int $assignmentVersion,
		?array $expectedState,
	): bool
	{
		return $this->saveAssignmentStateIfNotManual($userId, $siteId, [
			'CURRENT_SOURCE' => PresetSource::Managed->value,
			'CURRENT_PRESET_ID' => $presetId,
			'CURRENT_BUILTIN_ID' => null,
			'APPLIED_CONFIG_JSON' => Json::encode($config),
			'LAST_ASSIGNMENT_VERSION' => $assignmentVersion,
			'MANUALLY_SET_AT' => null,
			'UPDATED_AT' => new DateTime(),
		], $expectedState);
	}

	public function saveBuiltinAssignmentStateIfNotManual(
		int $userId,
		string $siteId,
		string $presetId,
		array $config,
		int $assignmentVersion,
		?array $expectedState,
	): bool
	{
		return $this->saveAssignmentStateIfNotManual($userId, $siteId, [
			'CURRENT_SOURCE' => PresetSource::Builtin->value,
			'CURRENT_PRESET_ID' => null,
			'CURRENT_BUILTIN_ID' => $presetId,
			'APPLIED_CONFIG_JSON' => Json::encode($config),
			'LAST_ASSIGNMENT_VERSION' => $assignmentVersion,
			'MANUALLY_SET_AT' => null,
			'UPDATED_AT' => new DateTime(),
		], $expectedState);
	}

	public function saveBuiltinSnapshotState(
		int $userId,
		string $siteId,
		string $presetId,
		array $config,
		bool $isManual = true,
	): void
	{
		$state = $this->getState($userId, $siteId);

		$this->upsert($userId, $siteId, [
			'CURRENT_SOURCE' => PresetSource::Builtin->value,
			'CURRENT_PRESET_ID' => null,
			'CURRENT_BUILTIN_ID' => $presetId,
			'APPLIED_CONFIG_JSON' => Json::encode($config),
			'LAST_ASSIGNMENT_VERSION' => (int)($state['LAST_ASSIGNMENT_VERSION'] ?? 0),
			'MANUALLY_SET_AT' => $isManual ? new DateTime() : null,
			'UPDATED_AT' => new DateTime(),
		]);
	}

	public function saveCheckoutIntentState(
		int $userId,
		string $siteId,
		string $presetId,
		array $config,
	): bool
	{
		return $this->saveCheckoutStateIfNotManual($userId, $siteId, [
			'CURRENT_SOURCE' => PresetSource::Intent->value,
			'CURRENT_PRESET_ID' => null,
			'CURRENT_BUILTIN_ID' => $presetId,
			'APPLIED_CONFIG_JSON' => Json::encode($config),
			'MANUALLY_SET_AT' => null,
			'UPDATED_AT' => new DateTime(),
		]);
	}

	public function saveManagedCheckoutIntentState(
		int $userId,
		string $siteId,
		int $presetId,
		array $config,
	): bool
	{
		return $this->saveCheckoutStateIfNotManual($userId, $siteId, [
			'CURRENT_SOURCE' => PresetSource::Intent->value,
			'CURRENT_PRESET_ID' => $presetId,
			'CURRENT_BUILTIN_ID' => null,
			'APPLIED_CONFIG_JSON' => Json::encode($config),
			'MANUALLY_SET_AT' => null,
			'UPDATED_AT' => new DateTime(),
		]);
	}

	public function saveManualState(int $userId, string $siteId, array $config, bool $isManual = true): void
	{
		$state = $this->getState($userId, $siteId);

		$this->upsert($userId, $siteId, [
			'CURRENT_SOURCE' => PresetSource::Manual->value,
			'CURRENT_PRESET_ID' => null,
			'CURRENT_BUILTIN_ID' => 'manual',
			'APPLIED_CONFIG_JSON' => Json::encode($config),
			'LAST_ASSIGNMENT_VERSION' => (int)($state['LAST_ASSIGNMENT_VERSION'] ?? 0),
			'MANUALLY_SET_AT' => $isManual ? new DateTime() : null,
			'UPDATED_AT' => new DateTime(),
		]);
	}

	public function importLegacyManualStateIfAbsent(
		int $userId,
		string $siteId,
		array $config,
		bool $isManual,
	): ?array
	{
		return $this->insertIfAbsent($userId, $siteId, [
			'CURRENT_SOURCE' => PresetSource::Manual->value,
			'CURRENT_PRESET_ID' => null,
			'CURRENT_BUILTIN_ID' => 'manual',
			'APPLIED_CONFIG_JSON' => Json::encode($config),
			'LAST_ASSIGNMENT_VERSION' => 0,
			'MANUALLY_SET_AT' => $isManual ? new DateTime() : null,
			'UPDATED_AT' => new DateTime(),
		]);
	}

	public function importLegacyBuiltinStateIfAbsent(
		int $userId,
		string $siteId,
		string $presetId,
		array $config,
		bool $isManual,
	): ?array
	{
		return $this->insertIfAbsent($userId, $siteId, [
			'CURRENT_SOURCE' => PresetSource::Builtin->value,
			'CURRENT_PRESET_ID' => null,
			'CURRENT_BUILTIN_ID' => $presetId,
			'APPLIED_CONFIG_JSON' => Json::encode($config),
			'LAST_ASSIGNMENT_VERSION' => 0,
			'MANUALLY_SET_AT' => $isManual ? new DateTime() : null,
			'UPDATED_AT' => new DateTime(),
		]);
	}

	public function markDeletedAssignmentSeen(int $userId, string $siteId, int $assignmentVersion): void
	{
		$state = $this->getState($userId, $siteId);
		$fields = [
			'LAST_ASSIGNMENT_VERSION' => $assignmentVersion,
			'UPDATED_AT' => new DateTime(),
		];

		if ($state && !empty($state['APPLIED_CONFIG_JSON']))
		{
			$fields['CURRENT_SOURCE'] = PresetSource::AppliedDeleted->value;
		}

		$this->upsert($userId, $siteId, $fields);
	}

	public function markDeletedAssignmentSeenIfNotManual(
		int $userId,
		string $siteId,
		int $assignmentVersion,
		?array $expectedState,
	): bool
	{
		$state = $expectedState;
		if ($state === null || !empty($state['MANUALLY_SET_AT']))
		{
			return false;
		}
		if ($assignmentVersion <= (int)($state['LAST_ASSIGNMENT_VERSION'] ?? 0))
		{
			return false;
		}

		$fields = [
			'LAST_ASSIGNMENT_VERSION' => $assignmentVersion,
			'UPDATED_AT' => new DateTime(),
		];
		if (!empty($state['APPLIED_CONFIG_JSON']))
		{
			$fields['CURRENT_SOURCE'] = PresetSource::AppliedDeleted->value;
		}

		return $this->updateIfMatchesAutomaticState($userId, $siteId, $fields, $state);
	}

	public function resetManagedState(int $userId, string $siteId): void
	{
		$this->upsert($userId, $siteId, [
			'CURRENT_SOURCE' => PresetSource::Builtin->value,
			'CURRENT_PRESET_ID' => null,
			'CURRENT_BUILTIN_ID' => null,
			'APPLIED_CONFIG_JSON' => null,
			'LAST_ASSIGNMENT_VERSION' => 0,
			'MANUALLY_SET_AT' => null,
			'UPDATED_AT' => new DateTime(),
		]);
	}

	public function resetManagedStateIfNotManual(
		int $userId,
		string $siteId,
		?array $expectedState,
	): bool
	{
		$state = $expectedState;
		if ($state === null)
		{
			return false;
		}

		return $this->updateIfMatchesAutomaticState($userId, $siteId, [
			'CURRENT_SOURCE' => PresetSource::Builtin->value,
			'CURRENT_PRESET_ID' => null,
			'CURRENT_BUILTIN_ID' => null,
			'APPLIED_CONFIG_JSON' => null,
			'LAST_ASSIGNMENT_VERSION' => 0,
			'MANUALLY_SET_AT' => null,
			'UPDATED_AT' => new DateTime(),
		], $state);
	}

	public function getAppliedConfig(array $state): ?array
	{
		if (empty($state['APPLIED_CONFIG_JSON']))
		{
			return null;
		}

		try
		{
			$config = Json::decode((string)$state['APPLIED_CONFIG_JSON']);
		}
		catch (\Throwable)
		{
			return null;
		}

		return is_array($config) ? $config : null;
	}

	private function upsert(int $userId, string $siteId, array $fields): void
	{
		if ($userId <= 0 || $siteId === '')
		{
			return;
		}

		$primary = [
			'USER_ID' => $userId,
			'SITE_ID' => $siteId,
		];
		if ($this->getState($userId, $siteId))
		{
			$this->ensureWriteSucceeded(UserPresetStateTable::update($primary, $fields));

			return;
		}
		else
		{
			try
			{
				$result = UserPresetStateTable::add($this->makeRecord($userId, $siteId, $fields));
			}
			catch (DuplicateEntryException)
			{
				$result = null;
			}
			if ($result?->isSuccess())
			{
				return;
			}

			if ($this->getState($userId, $siteId) === null)
			{
				$this->ensureWriteSucceeded($result);
			}

			$this->ensureWriteSucceeded(UserPresetStateTable::update($primary, $fields));
		}
	}

	private function ensureWriteSucceeded(?object $result): void
	{
		if ($result !== null && $result->isSuccess())
		{
			return;
		}

		$messages = $result?->getErrorMessages() ?? [];

		throw new SystemException(implode('; ', $messages) ?: 'Unable to save tab preset state');
	}

	private function saveAssignmentStateIfNotManual(
		int $userId,
		string $siteId,
		array $fields,
		?array $expectedState,
	): bool
	{
		if ($userId <= 0 || $siteId === '')
		{
			return false;
		}

		$state = $expectedState;
		if ($state === null)
		{
			try
			{
				$result = UserPresetStateTable::add($this->makeRecord($userId, $siteId, $fields));
			}
			catch (DuplicateEntryException)
			{
				$result = null;
			}
			if ($result?->isSuccess())
			{
				return true;
			}
		}

		if ($state === null || !empty($state['MANUALLY_SET_AT']))
		{
			return false;
		}
		if ((int)($fields['LAST_ASSIGNMENT_VERSION'] ?? 0) < (int)($state['LAST_ASSIGNMENT_VERSION'] ?? 0))
		{
			return false;
		}

		return $this->updateIfMatchesAutomaticState($userId, $siteId, $fields, $state);
	}

	private function saveCheckoutStateIfNotManual(int $userId, string $siteId, array $fields): bool
	{
		if ($userId <= 0 || $siteId === '')
		{
			return false;
		}

		$state = $this->getState($userId, $siteId);
		if ($state === null)
		{
			$fields['LAST_ASSIGNMENT_VERSION'] = 0;
			try
			{
				$result = UserPresetStateTable::add($this->makeRecord($userId, $siteId, $fields));
			}
			catch (DuplicateEntryException)
			{
				$result = null;
			}
			if ($result?->isSuccess())
			{
				return true;
			}

			$state = $this->getState($userId, $siteId);
			if ($state === null)
			{
				$this->ensureWriteSucceeded($result);
			}
		}

		if (!empty($state['MANUALLY_SET_AT']))
		{
			return false;
		}

		$fields['LAST_ASSIGNMENT_VERSION'] = (int)($state['LAST_ASSIGNMENT_VERSION'] ?? 0);

		return $this->updateIfMatchesAutomaticState($userId, $siteId, $fields, $state);
	}

	private function insertIfAbsent(int $userId, string $siteId, array $fields): ?array
	{
		if ($userId <= 0 || $siteId === '')
		{
			return null;
		}

		try
		{
			UserPresetStateTable::add($this->makeRecord($userId, $siteId, $fields));
		}
		catch (DuplicateEntryException)
		{
			return $this->getState($userId, $siteId);
		}

		return $this->getState($userId, $siteId);
	}

	private function makeRecord(int $userId, string $siteId, array $fields): array
	{
		return [
			'USER_ID' => $userId,
			'SITE_ID' => $siteId,
			'CURRENT_SOURCE' => $fields['CURRENT_SOURCE'] ?? PresetSource::Manual->value,
			'CURRENT_PRESET_ID' => $fields['CURRENT_PRESET_ID'] ?? null,
			'CURRENT_BUILTIN_ID' => $fields['CURRENT_BUILTIN_ID'] ?? null,
			'APPLIED_CONFIG_JSON' => $fields['APPLIED_CONFIG_JSON'] ?? null,
			'LAST_ASSIGNMENT_VERSION' => $fields['LAST_ASSIGNMENT_VERSION'] ?? 0,
			'MANUALLY_SET_AT' => $fields['MANUALLY_SET_AT'] ?? null,
			'UPDATED_AT' => $fields['UPDATED_AT'] ?? new DateTime(),
		];
	}

	private function updateIfMatchesAutomaticState(
		int $userId,
		string $siteId,
		array $fields,
		array $expectedState,
	): bool
	{
		if ($userId <= 0 || $siteId === '')
		{
			return false;
		}

		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$assignments = [];
		foreach ($fields as $field => $value)
		{
			$assignments[] = $field . ' = ' . $this->getSqlValue($sqlHelper, $value);
		}
		$conditions = [
			'USER_ID = ' . $userId,
			"SITE_ID = '" . $sqlHelper->forSql($siteId) . "'",
			'MANUALLY_SET_AT IS NULL',
		];
		foreach ($this->getExpectedStateFields($expectedState) as $field => $value)
		{
			$conditions[] = $this->getSqlCondition($sqlHelper, $field, $value);
		}

		$connection->queryExecute(sprintf(
			'UPDATE %s SET %s WHERE %s',
			UserPresetStateTable::getTableName(),
			implode(', ', $assignments),
			implode(' AND ', $conditions),
		));

		return $connection->getAffectedRowsCount() > 0;
	}

	private function getExpectedStateFields(array $state): array
	{
		return [
			'LAST_ASSIGNMENT_VERSION' => (int)($state['LAST_ASSIGNMENT_VERSION'] ?? 0),
			'CURRENT_SOURCE' => (string)($state['CURRENT_SOURCE'] ?? ''),
			'CURRENT_PRESET_ID' => $state['CURRENT_PRESET_ID'] ?? null,
			'CURRENT_BUILTIN_ID' => $state['CURRENT_BUILTIN_ID'] ?? null,
			'APPLIED_CONFIG_JSON' => $state['APPLIED_CONFIG_JSON'] ?? null,
		];
	}

	private function getSqlCondition(SqlHelper $sqlHelper, string $field, mixed $value): string
	{
		if ($value === null)
		{
			return $field . ' IS NULL';
		}

		return $field . ' = ' . $this->getSqlValue($sqlHelper, $value);
	}

	private function getSqlValue(SqlHelper $sqlHelper, mixed $value): string
	{
		if ($value === null)
		{
			return 'NULL';
		}

		if ($value instanceof DateTime)
		{
			return $sqlHelper->convertToDbDateTime($value);
		}

		if (is_int($value))
		{
			return (string)$value;
		}

		return '\'' . $sqlHelper->forSql((string)$value) . '\'';
	}
}
