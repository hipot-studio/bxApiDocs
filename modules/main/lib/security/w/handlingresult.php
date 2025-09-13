<?php

namespace Bitrix\Main\Security\W;

use Bitrix\Main\Security\W\Rules\Rule;
use Bitrix\Main\Security\W\Rules\Results\RuleResult;

class HandlingResult
{
	protected string $contextName;

	protected array $contextKey;

	protected RuleResult $ruleResult;

	protected Rule $rule;

	/**
	 * @param string $contextName
	 * @param array $contextKey
	 * @param \Bitrix\Main\Security\W\Rules\Results\RuleResult $ruleResult
	 * @param Rule $rule
	 */
	public function __construct(string $contextName, array $contextKey, RuleResult $ruleResult, Rule $rule)
	{
		$this->contextName = $contextName;
		$this->contextKey = $contextKey;
		$this->ruleResult = $ruleResult;
		$this->rule = $rule;
	}


	public function getContextName(): string
	{
		return $this->contextName;
	}

	public function setContextName(string $contextName): void
	{
		$this->contextName = $contextName;
	}

	public function getContextKey(): array
	{
		return $this->contextKey;
	}

	public function setContextKey(array $contextKey): void
	{
		$this->contextKey = $contextKey;
	}

	public function getRuleResult(): RuleResult
	{
		return $this->ruleResult;
	}

	public function setRuleResult(RuleResult $ruleResult): void
	{
		$this->ruleResult = $ruleResult;
	}

	public function getRule(): Rule
	{
		return $this->rule;
	}

	public function setRule(Rule $rule): void
	{
		$this->rule = $rule;
	}
}