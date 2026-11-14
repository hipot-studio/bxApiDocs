<?php

namespace Bitrix\Mobile\Internal\Onboarding\Activation;

/**
 * The facts "step -> completed" of a single user and a single path, computed on demand:
 * a step is checked when a rule asks about it and never checked twice within one
 * instance. Since the rules are first-match-wins (see ActivationResolver), the steps
 * behind the first uncompleted one cost nothing.
 *
 * The state answers only for the steps of its own path; anything outside it reads as
 * uncompleted and is never checked, so no path can spend a query on the steps of
 * another one.
 *
 * The instance lives for one resolve pass and is never stored, so nothing is carried
 * over between passes or between users - the memo is the whole lifetime of a fact.
 */
final class ActivationState
{
	/** @var array<string, ActivationStep> keyed by ActivationStep->value */
	private readonly array $steps;

	/** @var array<string, bool> keyed by ActivationStep->value */
	private array $completed = [];

	/**
	 * @param ActivationStep[] $steps the steps of the path this state answers for
	 * @param \Closure(ActivationStep): bool $checkStep the fact of a single step
	 */
	public function __construct(array $steps, private readonly \Closure $checkStep)
	{
		$keyed = [];
		foreach ($steps as $step)
		{
			$keyed[$step->value] = $step;
		}

		$this->steps = $keyed;
	}

	public function isCompleted(ActivationStep $step): bool
	{
		if (!isset($this->steps[$step->value]))
		{
			return false;
		}

		return $this->completed[$step->value] ??= ($this->checkStep)($step);
	}
}
