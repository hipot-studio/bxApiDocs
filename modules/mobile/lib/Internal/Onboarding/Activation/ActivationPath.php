<?php

namespace Bitrix\Mobile\Internal\Onboarding\Activation;

/**
 * A named, ordered set of steps and rules for a single activation scenario
 * (see PATH-MAP). Immutable: adding a step/rule/path never mutates an existing
 * path (path isolation invariant).
 *
 * Rules are ordered and evaluated first-match-wins (see ActivationResolver); for
 * the starter paths the rule set degenerates to "first uncompleted step -> its
 * push".
 */
final class ActivationPath
{
	/**
	 * @param ActivationStep[] $steps ordered
	 * @param PushRule[] $rules ordered, first-match-wins
	 * @param string[] $toolCodes qualification tool codes selecting this path
	 * @param int $priority wins over a lower one when several paths match
	 */
	public function __construct(
		private readonly string $code,
		private readonly array $steps,
		private readonly array $rules,
		private readonly array $toolCodes = [],
		private readonly int $priority = 0,
	)
	{
	}

	public function getCode(): string
	{
		return $this->code;
	}

	/**
	 * @return string[]
	 */
	public function getToolCodes(): array
	{
		return $this->toolCodes;
	}

	public function getPriority(): int
	{
		return $this->priority;
	}

	/**
	 * @return ActivationStep[] ordered
	 */
	public function getSteps(): array
	{
		return $this->steps;
	}

	/**
	 * @return PushRule[] ordered, first-match-wins
	 */
	public function getRules(): array
	{
		return $this->rules;
	}
}
