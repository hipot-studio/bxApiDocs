<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Restrictions;

/**
 * Format policy one user writes under: the global rule, the private rules of the first level that
 * has any for the user, or both.
 */
final readonly class EffectivePolicy
{
	public const LEVEL_TEAM = 'team';
	public const LEVEL_GLOBAL = 'global';

	/**
	 * @param array<string, true> $extensionMap Restricted extensions as a map to look up by.
	 * @param bool $blockNoExtension Whether files without an extension are restricted.
	 * @param int $changedAt Last moment the policy of the user may have changed, for session amnesty.
	 * @param string $level Level the policy comes from: user, team, department or global.
	 * @param bool $usesGlobal Whether the global rule is part of the policy.
	 * @param int[] $ruleIds Ids of the private rules the policy is made of.
	 */
	public function __construct(
		public array $extensionMap,
		public bool $blockNoExtension,
		public int $changedAt,
		public string $level,
		public bool $usesGlobal,
		public array $ruleIds,
	)
	{
	}

	public function isEmpty(): bool
	{
		return $this->extensionMap === [] && !$this->blockNoExtension;
	}
}
