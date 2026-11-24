<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Entity\Marketing;

/**
 * The outcome of a selection for a pack of users: every requested rule evaluated for every user.
 * There is no priority between the rules and no value of any fact - only codes, verdicts, choices and
 * moments.
 */
final class SelectionResult
{
	/** @var array<int, array<string, RuleEvaluation>> */
	private readonly array $evaluations;

	/**
	 * @param array<int, RuleEvaluation[]> $evaluations userId => evaluations in the order the rules were asked
	 */
	public function __construct(array $evaluations)
	{
		$keyed = [];
		foreach ($evaluations as $userId => $userEvaluations)
		{
			$keyed[$userId] = [];
			foreach ($userEvaluations as $evaluation)
			{
				$keyed[$userId][$evaluation->ruleCode] = $evaluation;
			}
		}

		$this->evaluations = $keyed;
	}

	/**
	 * @return int[]
	 */
	public function getUserIds(): array
	{
		return array_keys($this->evaluations);
	}

	/**
	 * @return array<string, RuleEvaluation> rule code => evaluation; empty for a user outside the pack
	 */
	public function getEvaluations(int $userId): array
	{
		return $this->evaluations[$userId] ?? [];
	}

	/**
	 * @return string[] codes of the rules that matched, in the order they were asked
	 */
	public function getMatchedCodes(int $userId): array
	{
		$matched = array_filter(
			$this->getEvaluations($userId),
			static fn(RuleEvaluation $evaluation): bool => $evaluation->verdict === Verdict::Matched,
		);

		return array_keys($matched);
	}

	/**
	 * Whether a rule of the user was left undecided by a source that could not be read.
	 */
	public function isFailed(int $userId): bool
	{
		foreach ($this->getEvaluations($userId) as $evaluation)
		{
			if ($evaluation->isFailed())
			{
				return true;
			}
		}

		return false;
	}
}
