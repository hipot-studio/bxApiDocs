<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Definition;

/**
 * One OR-branch of a recent {@see Selection}. A branch is either section-based
 * (carries {@see $typeCondition}) or an explicit chat list ({@see $chatIds}),
 * never both. Turning a branch into an SQL condition is the recent provider's job.
 */
final class SelectionBranch
{
	/**
	 * @param string|null $typeCondition recent section code the branch filters by; null for an explicit chat list
	 * @param int|null $parentChatId nesting frame: 0 roots only, null all levels, >0 children of the chat
	 * @param int[]|null $chatIds explicit chat ids; null when the branch is section-based
	 */
	public function __construct(
		public readonly ?string $typeCondition,
		public readonly ?int $parentChatId,
		public readonly ?array $chatIds,
	) {}
}
