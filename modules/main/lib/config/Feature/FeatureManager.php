<?php

namespace Bitrix\Main\Config\Feature;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Psr\Log\LoggerInterface;

class FeatureManager
{
	private const FLAG_VALUES_CACHE_TTL = 86400;
	private const RULES_CACHE_TTL = 86400;

	private ?array $rulesMap = null;

	/** @var array<string, bool>|null */
	private ?array $flagValues = null;

	public function __construct(
		private readonly Factory $flagFactory,
		private readonly LoggerInterface $logger,
	)
	{
	}

	/**
	 * Checks if a feature is enabled.
	 *
	 * @param class-string<AbstractFlag> $flag The feature flag class to check.
	 * @param Context|null $context The context for the check (e.g., a specific user).
	 * @return bool
	 */
	public function isEnabled(string $flag, ?Context $context = null): bool
	{
		$flagInstance = $this->flagFactory->resolveFlag($flag);

		// 1. Cannot resolve feature class - assume feature was enabled and removed from codebase
		if ($flagInstance === null)
		{
			return true;
		}

		$context ??= Context::getCurrent();

		// 2. Check requirements
		foreach ($flagInstance->getRequirements() as $requirement)
		{
			if (!is_callable($requirement))
			{
				continue;
			}

			$requirementResult = (bool)$requirement($context);
			if ($requirementResult === false)
			{
				return false;
			}
		}

		// 3. Check deny rules first
		if ($this->findRuleMatchedByPolicy($flagInstance, $context, RulePolicy::DENY))
		{
			return false;
		}

		// 4. Then check allow rules
		if ($this->findRuleMatchedByPolicy($flagInstance, $context, RulePolicy::ALLOW))
		{
			return true;
		}

		// 5. Check flag value in database
		$dbValue = $this->getFlagDbValue($flagInstance);
		if ($dbValue !== null)
		{
			return $dbValue;
		}

		// 6. Check default value
		return $flagInstance->enabledByDefault();
	}

	/**
	 * Checks if a feature is disabled.
	 *
	 * @param class-string<AbstractFlag> $flag The feature flag class to check.
	 * @param Context|null $context The context for the check (e.g., a specific user).
	 * @return bool
	 */
	public function isDisabled(string $flag, ?Context $context = null): bool
	{
		return !$this->isEnabled($flag, $context);
	}

	/**
	 * Enables one or more feature flags.
	 *
	 * @param class-string<AbstractFlag> ...$flags
	 * @return void
	 */
	public function enable(string ...$flags): void
	{
		$this->toggle(true, ...$flags);
	}

	/**
	 * Disables one or more feature flags.
	 *
	 * @param class-string<AbstractFlag> ...$flags
	 * @return void
	 */
	public function disable(string ...$flags): void
	{
		$this->toggle(false, ...$flags);
	}

	/**
	 * @param bool $newValue
	 * @param class-string<AbstractFlag> ...$flags
	 * @return void
	 */
	private function toggle(bool $newValue, string ...$flags): void
	{
		$userId = CurrentUser::get()->getId();

		foreach ($flags as $flagClass)
		{
			$flagInstance = $this->flagFactory->resolveFlag($flagClass);
			if ($flagInstance === null)
			{
				continue;
			}

			$currentValue = $this->getFlagDbValue($flagInstance);
			if ($currentValue === $newValue)
			{
				continue;
			}

			$moduleId = $this->flagFactory->resolveModule($flagInstance->getCode());
			if ($moduleId === null)
			{
				continue;
			}

			$result = FeatureFlagTable::add([
				'CODE' => $flagInstance->getCode(),
				'MODULE_ID' => $moduleId,
				'ENABLED' => $newValue,
				'MODIFIED_BY' => $userId,
			]);

			if ($result->isSuccess())
			{
				$this->clearFlagValuesCache();
				$flagInstance->onFlagValueChange($newValue);
			}
			else
			{
				$this->logger->error('FeatureFlags: cannot store value for feature', [
					'feature' => $flagInstance->getCode(),
					'reason' => $result->getError()?->getMessage() ?? 'unknown',
				]);
			}
		}
	}

	private function getFlagDbValue(AbstractFlag $flagInstance): ?bool
	{
		if ($this->flagValues === null)
		{
			$this->flagValues = [];
			FeatureFlagTable::query()
				->setSelect(['CODE', 'ENABLED'])
				->setCacheTtl(self::FLAG_VALUES_CACHE_TTL)
				->exec()
				->fetchCollection()
				->walk(function (EO_FeatureFlag $item) {
					$this->flagValues[$item->getCode()] = $item->getEnabled();
				})
			;
		}

		return $this->flagValues[$flagInstance->getCode()] ?? null;
	}

	/**
	 * Adds a rule to conditionally allow a feature.
	 *
	 * @param class-string<AbstractFlag> $flag The feature flag class.
	 * @param class-string<AbstractRule> $rule The rule class.
	 * @param array $config The configuration for the rule.
	 * @return void
	 */
	public function allow(string $flag, string $rule, array $config = []): void
	{
		$this->addRule($flag, $rule, $config, RulePolicy::ALLOW);
	}

	/**
	 * Adds a rule to conditionally deny a feature.
	 *
	 * @param class-string<AbstractFlag> $flag The feature flag class.
	 * @param class-string<AbstractRule> $rule The rule class.
	 * @param array $config The configuration for the rule.
	 * @return void
	 */
	public function deny(string $flag, string $rule, array $config = []): void
	{
		$this->addRule($flag, $rule, $config, RulePolicy::DENY);
	}

	private function addRule(string $flag, string $rule, array $config, RulePolicy $policy): void
	{
		$flagInstance = $this->flagFactory->resolveFlag($flag);
		if ($flagInstance === null)
		{
			return;
		}

		$ruleInstance = $this->flagFactory->resolveRule($rule, $config);
		if ($ruleInstance === null)
		{
			return;
		}

		$result = FeatureFlagRuleTable::add([
			'FEATURE_CODE' => $flagInstance->getCode(),
			'POLICY' => $policy->value,
			'RULE_CODE' => $rule,
			'RULE_ARGS' => $config,
			'MODIFIED_BY' => CurrentUser::get()->getId(),
		]);

		if ($result->isSuccess())
		{
			$this->clearRulesCache();
		}
		else
		{
			$this->logger->error('FeatureFlags: cannot add rule for feature', [
				'feature' => $flagInstance->getCode(),
				'rule' => $rule,
				'reason' => $result->getError()?->getMessage() ?? 'unknown',
			]);
		}
	}

	private function loadRules(): void
	{
		if ($this->rulesMap !== null)
		{
			return;
		}

		$this->rulesMap = [];

		FeatureFlagRuleTable::query()
			->setSelect(['FEATURE_CODE', 'POLICY', 'RULE_CODE', 'RULE_ARGS'])
			->addOrder('ID', 'DESC')
			->setCacheTtl(self::RULES_CACHE_TTL)
			->exec()
			->fetchCollection()
			->walk(function (EO_FeatureFlagRule $item) {
				$policy = RulePolicy::tryFrom($item->getPolicy());
				if ($policy === null)
				{
					return;
				}

				$featureCode = $item->getFeatureCode();

				$this->rulesMap[$featureCode][$policy->value] ??= [];
				$this->rulesMap[$featureCode][$policy->value][] = $item;
			})
		;
	}

	private function findRuleMatchedByPolicy(AbstractFlag $flagInstance, Context $context, RulePolicy $policy): bool
	{
		$this->loadRules();

		/** @var EO_FeatureFlagRule[] $rules */
		$rules = $this->rulesMap[$flagInstance->getCode()][$policy->value] ?? [];

		foreach ($rules as $ruleEntity)
		{
			$ruleInstance = $this->flagFactory->resolveRule($ruleEntity->getRuleCode(), $ruleEntity->getRuleArgs());
			if ($ruleInstance === null)
			{
				continue;
			}

			if ($ruleInstance->check($context))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Clears runtime rules for a feature flag.
	 *
	 * @param class-string<AbstractFlag> $flag The feature flag class.
	 * @param class-string<AbstractRule> ...$rules If provided, only these rules will be cleared.
	 * @return void
	 */
	public function clearRules(string $flag, string ...$rules): void
	{
		$flagInstance = $this->flagFactory->resolveFlag($flag);
		if ($flagInstance === null)
		{
			return;
		}

		$filter = (new ConditionTree())->where('FEATURE_CODE', $flagInstance->getCode());

		if (!empty($rules))
		{
			$filter->whereIn('RULE_CODE', $rules);
		}

		FeatureFlagRuleTable::deleteByFilter($filter);

		$this->clearRulesCache();
	}

	/**
	 * Resets previously toggled feature flags to default value.
	 * Note that this method don't remove rules, use specific clearRules() method for that purpose.
	 *
	 * @param class-string<AbstractFlag> ...$flags The feature flag classes.
	 * @return void
	 */
	public function resetToDefault(string ...$flags): void
	{
		$codes = [];

		foreach ($flags as $flagClass)
		{
			$flagInstance = $this->flagFactory->resolveFlag($flagClass);
			if ($flagInstance !== null)
			{
				$codes[] = $flagInstance->getCode();
			}
		}

		if (!empty($codes))
		{
			$filter = (new ConditionTree())->whereIn('CODE', $codes);
			FeatureFlagTable::deleteByFilter($filter);

			$this->clearFlagValuesCache();
		}
	}

	/**
	 * Clears whole cache, both in-memory and ORM
	 *
	 * @return void
	 */
	public function clearCache(): void
	{
		$this->clearFlagValuesCache();
		$this->clearRulesCache();

		FeatureFlagTable::getEntity()->cleanCache();
		FeatureFlagRuleTable::getEntity()->cleanCache();
	}

	private function clearRulesCache(): void
	{
		$this->rulesMap = null;
	}

	private function clearFlagValuesCache(): void
	{
		$this->flagValues = null;
	}
}
