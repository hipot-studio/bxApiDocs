<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Entity\Marketing;

use Bitrix\Main\Type\DateTime;

/**
 * Whom the rules are asked about, in which channel and placement, and at which moment. The portal is
 * the one of the environment, and facts cannot be handed in: they are read by their sources.
 *
 * The context does not judge the pack - an empty one or a non-positive identifier is an error of the
 * whole evaluation, reported by the evaluator.
 */
final class EvaluationContext
{
	/**
	 * @param int[] $userIds
	 */
	private function __construct(
		public readonly array $userIds,
		public readonly string $channel,
		public readonly ?string $placement,
		public readonly DateTime $now,
	)
	{
	}

	/**
	 * @param array<int|string> $userIds
	 */
	public static function forUsers(
		array $userIds,
		string $channel,
		?string $placement = null,
		?DateTime $now = null,
	): self
	{
		$distinct = array_values(array_unique(array_map(static fn(int|string $id): int => (int)$id, $userIds)));

		return new self($distinct, $channel, $placement, $now ?? new DateTime());
	}

	public static function forUser(int $userId, string $channel, ?string $placement = null, ?DateTime $now = null): self
	{
		return self::forUsers([$userId], $channel, $placement, $now);
	}
}
