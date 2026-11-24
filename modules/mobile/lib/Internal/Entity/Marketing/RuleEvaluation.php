<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Entity\Marketing;

use Bitrix\Main\Type\DateTime;

/**
 * How one rule was decided for one user: the verdict, the alternatives its choices picked and the
 * moment the decision holds for. The facts behind it are not part of it.
 *
 * A failed evaluation is an unknown verdict a fact decided whose source could not be read: unlike an
 * unknown nobody could know, it is not an answer, and a caller must not act on it as on one.
 */
final class RuleEvaluation
{
	/**
	 * @param array<string, string> $chosen name of a choice => code of the alternative it picked
	 */
	public function __construct(
		public readonly string $ruleCode,
		public readonly Verdict $verdict,
		public readonly array $chosen,
		public readonly DateTime $evaluatedAt,
		private readonly bool $failed = false,
	)
	{
	}

	public function isFailed(): bool
	{
		return $this->failed;
	}
}
