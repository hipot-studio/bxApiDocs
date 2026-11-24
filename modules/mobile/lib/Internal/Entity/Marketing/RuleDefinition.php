<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Entity\Marketing;

use Bitrix\Main\ArgumentException;

/**
 * A rule of selection: the channel and the placements it acts in, the conditions that admit a user
 * and, optionally, the choice of a path or a step for an admitted one. The same form serves a rule
 * written in code and a rule built from data.
 */
final class RuleDefinition
{
	/**
	 * @param string[] $placements where in the channel the rule acts; empty - wherever the channel is
	 * @throws ArgumentException
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $channel,
		public readonly array $placements,
		public readonly ConditionGroup $conditions,
		public readonly ?Choice $choice = null,
	)
	{
		if ($channel === '')
		{
			throw new ArgumentException('Rule "' . $code . '" names no channel', 'channel');
		}
	}
}
