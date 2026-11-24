<?php

declare(strict_types=1);

namespace Bitrix\Pull\Service\PushSchedule;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class PolicyValidator
{
	public const ERROR_VALIDATION = 'VALIDATION_ERROR';
	public const LEGACY_RULE_NAME_SENTINEL = '';
	public const DEFAULT_RULE_ICON = 'other';
	public const MAX_RULE_NAME_LENGTH = 100;
	public const MAX_RULES = 100;
	public const MAX_EXCEPTION_SOURCE_IDS = 1000;
	public const RULE_ICONS = ['vacation', 'sick', 'suitcase', 'home', 'other'];

	public function validate(
		mixed $revision,
		mixed $automaticEnabled,
		mixed $rules,
		mixed $exceptionSourceIds,
	): Result
	{
		$result = new Result();
		if (!is_int($revision) || $revision < 0)
		{
			return $this->validationError($result, 'revision');
		}

		if (!is_bool($automaticEnabled))
		{
			return $this->validationError($result, 'automaticEnabled');
		}

		$normalizedRules = $this->validateRules($result, $rules);
		if ($normalizedRules instanceof Result)
		{
			return $normalizedRules;
		}

		$normalizedExceptionSourceIds = $this->validateExceptionSourceIds($result, $exceptionSourceIds);
		if ($normalizedExceptionSourceIds instanceof Result)
		{
			return $normalizedExceptionSourceIds;
		}

		$result->setData([
			'revision' => $revision,
			'automaticEnabled' => $automaticEnabled,
			'rules' => $normalizedRules,
			'exceptionSourceIds' => $normalizedExceptionSourceIds,
		]);

		return $result;
	}

	private function validateRules(Result $result, mixed $rules): array|Result
	{
		if (!is_array($rules))
		{
			return $this->validationError($result, 'rules');
		}
		if (count($rules) > self::MAX_RULES)
		{
			return $this->validationError($result, 'rules');
		}

		$normalizedRules = [];
		$ruleIds = [];
		foreach ($rules as $ruleIndex => $rule)
		{
			$normalizedRule = $this->validateRule($result, $rule, (int)$ruleIndex, $ruleIds);
			if ($normalizedRule instanceof Result)
			{
				return $normalizedRule;
			}

			if ($normalizedRule['id'] !== null)
			{
				$ruleIds[$normalizedRule['id']] = true;
			}
			$normalizedRules[] = $normalizedRule;
		}

		return $normalizedRules;
	}

	private function validateRule(Result $result, mixed $rule, int $ruleIndex, array $ruleIds): array|Result
	{
		if (!is_array($rule))
		{
			return $this->validationError($result, 'rules', $ruleIndex);
		}

		$id = $rule['id'] ?? null;
		if ($id !== null && (!is_int($id) || $id < 1))
		{
			return $this->validationError($result, 'rules.id', $ruleIndex);
		}
		if ($id !== null && isset($ruleIds[$id]))
		{
			return $this->validationError($result, 'rules.id', $ruleIndex);
		}
		if (!array_key_exists('active', $rule) || !is_bool($rule['active']))
		{
			return $this->validationError($result, 'rules.active', $ruleIndex);
		}

		$name = null;
		if (array_key_exists('name', $rule))
		{
			if (!is_string($rule['name']))
			{
				return $this->validationError($result, 'rules.name', $ruleIndex);
			}

			$name = preg_replace('/^[\p{Z}\s\x{FEFF}]+|[\p{Z}\s\x{FEFF}]+$/u', '', $rule['name']);
			if ($name === null || $name === '' || mb_strlen($name) > self::MAX_RULE_NAME_LENGTH)
			{
				return $this->validationError($result, 'rules.name', $ruleIndex);
			}
		}

		$icon = null;
		if (array_key_exists('icon', $rule))
		{
			if (!is_string($rule['icon']) || !in_array($rule['icon'], self::RULE_ICONS, true))
			{
				return $this->validationError($result, 'rules.icon', $ruleIndex);
			}

			$icon = $rule['icon'];
		}

		if ($id === null)
		{
			$name ??= self::LEGACY_RULE_NAME_SENTINEL;
			$icon ??= self::DEFAULT_RULE_ICON;
		}

		$days = $this->validateDays($result, $rule, $ruleIndex);
		if ($days instanceof Result)
		{
			return $days;
		}

		$startMinute = $rule['startMinute'] ?? null;
		if (!is_int($startMinute) || $startMinute < 0 || $startMinute > 1439)
		{
			return $this->validationError($result, 'rules.startMinute', $ruleIndex);
		}

		$endMinute = $rule['endMinute'] ?? null;
		if (!is_int($endMinute) || $endMinute < 0 || $endMinute > 1439 || $endMinute === $startMinute)
		{
			return $this->validationError($result, 'rules.endMinute', $ruleIndex);
		}

		return [
			'id' => $id,
			'active' => $rule['active'],
			'name' => $name,
			'icon' => $icon,
			'days' => $days,
			'startMinute' => $startMinute,
			'endMinute' => $endMinute,
		];
	}

	private function validateDays(Result $result, array $rule, int $ruleIndex): array|Result
	{
		if (!isset($rule['days']) || !is_array($rule['days']) || $rule['days'] === [])
		{
			return $this->validationError($result, 'rules.days', $ruleIndex);
		}

		$days = [];
		foreach ($rule['days'] as $day)
		{
			if (!is_int($day) || $day < 1 || $day > 7 || isset($days[$day]))
			{
				return $this->validationError($result, 'rules.days', $ruleIndex);
			}
			$days[$day] = true;
		}
		$days = array_keys($days);
		sort($days);

		return $days;
	}

	private function validateExceptionSourceIds(Result $result, mixed $exceptionSourceIds): array|Result
	{
		if (!is_array($exceptionSourceIds))
		{
			return $this->validationError($result, 'exceptionSourceIds');
		}
		if (count($exceptionSourceIds) > self::MAX_EXCEPTION_SOURCE_IDS)
		{
			return $this->validationError($result, 'exceptionSourceIds');
		}

		$normalizedExceptionSourceIds = [];
		foreach ($exceptionSourceIds as $sourceId)
		{
			if (!is_string($sourceId) || $sourceId === '' || mb_strlen($sourceId) > 64)
			{
				return $this->validationError($result, 'exceptionSourceIds');
			}
			if (isset($normalizedExceptionSourceIds[$sourceId]))
			{
				return $this->validationError($result, 'exceptionSourceIds');
			}
			$normalizedExceptionSourceIds[$sourceId] = true;
		}

		return array_keys($normalizedExceptionSourceIds);
	}

	private function validationError(Result $result, string $field, ?int $ruleIndex = null): Result
	{
		return $result->addError(new Error('Invalid push schedule policy.', self::ERROR_VALIDATION, [
			'field' => $field,
			'ruleIndex' => $ruleIndex,
		]));
	}
}
