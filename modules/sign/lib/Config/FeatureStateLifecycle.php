<?php

namespace Bitrix\Sign\Config;

use Bitrix\Main;
use Bitrix\Main\Config\Feature\FeatureFlagRuleTable;
use Bitrix\Main\Config\Feature\FeatureFlagTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Sign\Config\Feature\B2eBizprocChainFlag;
use Bitrix\Sign\Config\Feature\KedoStageReorderFlag;
use Bitrix\Sign\Config\Feature\KedoRunProcessFlag;

final class FeatureStateLifecycle
{
	public const SNAPSHOT_OPTION = 'SIGN_KEDO_BIZPROC_FLAGS_MAIN_SNAPSHOT';

	private const MODULE_ID = 'sign';
	private const FLAGS = [
		B2eBizprocChainFlag::class,
		KedoStageReorderFlag::class,
		KedoRunProcessFlag::class,
	];

	public static function preserve(): void
	{
		Option::set(
			self::MODULE_ID,
			self::SNAPSHOT_OPTION,
			json_encode(self::readState(), JSON_THROW_ON_ERROR),
		);
	}

	public static function restore(): bool
	{
		$value = Option::getRealValue(self::MODULE_ID, self::SNAPSHOT_OPTION, '');
		if ($value === null)
		{
			return true;
		}

		try
		{
			$state = self::normalizeState(json_decode($value, true, flags: JSON_THROW_ON_ERROR));
			if ($state === null)
			{
				return false;
			}

			if (self::readState() !== $state)
			{
				self::replaceState($state);
			}

			Option::delete(self::MODULE_ID, [
				'name' => self::SNAPSHOT_OPTION,
				'site_id' => '',
			]);

			return true;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public static function clear(): void
	{
		self::deleteStoredState();
		Option::delete(self::MODULE_ID, [
			'name' => self::SNAPSHOT_OPTION,
			'site_id' => '',
		]);
		Option::delete(self::MODULE_ID, [
			'name' => Feature::SIGN_KEDO_BIZPROC_FLAGS_MIGRATED_TO_MAIN_OPTION,
			'site_id' => '',
		]);
	}

	private static function readState(): array
	{
		$flags = FeatureFlagTable::query()
			->setSelect(['CODE', 'ENABLED', 'MODIFIED_BY'])
			->whereIn('CODE', self::FLAGS)
			->setOrder(['CODE' => 'ASC'])
			->exec()
			->fetchAll()
		;
		foreach ($flags as &$flag)
		{
			$flag['ENABLED'] = self::isEnabledValue($flag['ENABLED']);
			$flag['MODIFIED_BY'] = isset($flag['MODIFIED_BY']) ? (int)$flag['MODIFIED_BY'] : null;
		}
		unset($flag);

		$rules = FeatureFlagRuleTable::query()
			->setSelect(['FEATURE_CODE', 'POLICY', 'RULE_CODE', 'RULE_ARGS', 'MODIFIED_BY'])
			->whereIn('FEATURE_CODE', self::FLAGS)
			->setOrder(['ID' => 'ASC'])
			->exec()
			->fetchAll()
		;
		foreach ($rules as &$rule)
		{
			$rule['MODIFIED_BY'] = isset($rule['MODIFIED_BY']) ? (int)$rule['MODIFIED_BY'] : null;
		}
		unset($rule);

		return [
			'flags' => $flags,
			'rules' => $rules,
		];
	}

	private static function normalizeState(mixed $state): ?array
	{
		if (!is_array($state) || !is_array($state['flags'] ?? null) || !is_array($state['rules'] ?? null))
		{
			return null;
		}

		$flags = [];
		foreach ($state['flags'] as $flag)
		{
			if (
				!is_array($flag)
				|| !in_array($flag['CODE'] ?? null, self::FLAGS, true)
				|| !is_bool($flag['ENABLED'] ?? null)
				|| (!is_int($flag['MODIFIED_BY'] ?? null) && ($flag['MODIFIED_BY'] ?? null) !== null)
			)
			{
				return null;
			}

			$flags[$flag['CODE']] = $flag;
		}
		ksort($flags);

		$policies = array_column(Main\Config\Feature\RulePolicy::cases(), 'value');
		$rules = [];
		foreach ($state['rules'] as $rule)
		{
			if (
				!is_array($rule)
				|| !in_array($rule['FEATURE_CODE'] ?? null, self::FLAGS, true)
				|| !in_array($rule['POLICY'] ?? null, $policies, true)
				|| !is_string($rule['RULE_CODE'] ?? null)
				|| $rule['RULE_CODE'] === ''
				|| (!is_array($rule['RULE_ARGS'] ?? null) && ($rule['RULE_ARGS'] ?? null) !== null)
				|| (!is_int($rule['MODIFIED_BY'] ?? null) && ($rule['MODIFIED_BY'] ?? null) !== null)
			)
			{
				return null;
			}

			$rules[] = $rule;
		}

		return [
			'flags' => array_values($flags),
			'rules' => $rules,
		];
	}

	private static function replaceState(array $state): void
	{
		$connection = Main\Application::getConnection();
		$connection->startTransaction();
		try
		{
			self::deleteStoredState(clearCache: false);

			foreach ($state['flags'] as $flag)
			{
				$result = FeatureFlagTable::add([
					'CODE' => $flag['CODE'],
					'MODULE_ID' => self::MODULE_ID,
					'ENABLED' => $flag['ENABLED'],
					'MODIFIED_BY' => $flag['MODIFIED_BY'],
				]);
				if (!$result->isSuccess())
				{
					throw new Main\SystemException(implode('; ', $result->getErrorMessages()));
				}
			}

			foreach ($state['rules'] as $rule)
			{
				$result = FeatureFlagRuleTable::add($rule);
				if (!$result->isSuccess())
				{
					throw new Main\SystemException(implode('; ', $result->getErrorMessages()));
				}
			}

			if (self::readState() !== $state)
			{
				throw new Main\SystemException('Feature flag state was not restored');
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();

			throw $exception;
		}
		finally
		{
			Main\Config\Feature::clearCache();
		}
	}

	private static function deleteStoredState(bool $clearCache = true): void
	{
		FeatureFlagRuleTable::deleteByFilter(
			(new ConditionTree())->whereIn('FEATURE_CODE', self::FLAGS),
		);
		FeatureFlagTable::deleteByFilter(
			(new ConditionTree())->whereIn('CODE', self::FLAGS),
		);
		if ($clearCache)
		{
			Main\Config\Feature::clearCache();
		}
	}

	private static function isEnabledValue(mixed $value): bool
	{
		return $value === true || $value === 1 || $value === '1' || $value === 'Y';
	}
}
