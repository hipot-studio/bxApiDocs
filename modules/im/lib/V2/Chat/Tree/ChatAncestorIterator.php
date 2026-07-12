<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree;

use Bitrix\Im\V2\Chat;

/**
 * Runtime walker over a chat's parent chain.
 *
 * Pure iteration helper: yields ancestors lazily, stops at $maxDepth or when a parent
 * cannot be resolved. Callers do whatever they need at each level (send pulls, query data,
 * narrow user cohorts, ...). No SQL — for query-time ancestor joins see {@see ChatAncestorNavigator}.
 */
final class ChatAncestorIterator
{
	/**
	 * @return iterable<int, Chat> 1-based depth (1 = parent, 2 = grandparent, ...) => ancestor
	 */
	public function ancestorsOf(Chat $chat, int $maxDepth = ChatAncestorNavigator::DEFAULT_DEPTH): \Generator
	{
		$current = $chat;
		$depth = 0;
		while ($current->hasParent() && $depth < $maxDepth)
		{
			$parent = $current->getParentChat();
			if ($parent === null)
			{
				break;
			}
			$depth++;
			yield $depth => $parent;
			$current = $parent;
		}
	}

	/**
	 * @return iterable<int, Chat> 0 => $chat, 1 => parent, 2 => grandparent, ...
	 */
	public function selfAndAncestorsOf(Chat $chat, int $maxDepth = ChatAncestorNavigator::DEFAULT_DEPTH): \Generator
	{
		yield 0 => $chat;
		yield from $this->ancestorsOf($chat, $maxDepth);
	}
}
