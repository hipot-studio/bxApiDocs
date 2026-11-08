<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Definition;

use LogicException;

/**
 * Recent selection as a set of OR-branches. A folder definition owns the
 * expansion of its composition into branches. This is a pure value object:
 * it describes the composition and reshapes its branches (see
 * {@see self::rootFramed()}); executing it against the recent query path is
 * the job of {@see SelectionResolver}, so list, counter and pins share one
 * execution path and never diverge.
 */
final class Selection
{
	private const ROOT_PARENT_CHAT_ID = 0;

	/** @var SelectionBranch[] */
	private readonly array $branches;

	public function __construct(SelectionBranch ...$branches)
	{
		$this->branches = $branches;
	}

	/**
	 * @return SelectionBranch[]
	 */
	public function getBranches(): array
	{
		return $this->branches;
	}

	public function isEmpty(): bool
	{
		return $this->branches === [];
	}

	/**
	 * Sole branch of a single-branch selection (section / chat-list definitions).
	 *
	 * @throws LogicException when the selection does not hold exactly one branch
	 */
	public function singleBranch(): SelectionBranch
	{
		if (count($this->branches) !== 1)
		{
			throw new LogicException('Selection does not hold exactly one branch');
		}

		return $this->branches[0];
	}

	/**
	 * A copy with section branches forced to the root frame (parentChatId == 0);
	 * explicit chat-list branches are kept as-is.
	 */
	public function rootFramed(): self
	{
		return new self(...array_map(
			static fn (SelectionBranch $branch): SelectionBranch => new SelectionBranch(
				typeCondition: $branch->typeCondition,
				parentChatId: $branch->chatIds === null ? self::ROOT_PARENT_CHAT_ID : $branch->parentChatId,
				chatIds: $branch->chatIds,
			),
			$this->branches,
		));
	}
}
