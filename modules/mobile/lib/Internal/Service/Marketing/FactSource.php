<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Service\Marketing;

use Bitrix\Mobile\Internal\Entity\Marketing\EvaluationContext;
use Bitrix\Mobile\Internal\Entity\Marketing\FactDefinition;

/**
 * Answers facts, never decisions: whether a communication fits is up to the rules. Declaring the facts
 * reads nothing; a value is read only when a condition reaches it.
 */
interface FactSource
{
	/**
	 * @return FactDefinition[]
	 */
	public function getDefinitions(): array;

	/**
	 * Answers one fact for a whole pack at once.
	 *
	 * @param array $params parameters of the condition, such as the "since" threshold of a dated action
	 * @param int[] $userIds users still undecided; ignored by a fact that is not per user
	 * @return array<int, mixed> userId => value, or 0 => value for a fact that is not per user; null is unknown
	 * @throws PartialReadException when the read failed for some users only: they alone are failed
	 * @throws \Throwable when the read failed as a whole: every user of the read is failed
	 */
	public function read(string $code, array $params, EvaluationContext $context, array $userIds): array;
}
