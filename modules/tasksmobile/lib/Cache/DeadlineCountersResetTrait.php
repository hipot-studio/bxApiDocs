<?php

declare(strict_types=1);

namespace Bitrix\TasksMobile\Cache;

use Bitrix\Main\Engine\Action;

/**
 * Drops the cached deadline counters of the acting user after every action of the controller that is not a
 * read. The board is moved by the task itself, by its results, files, members, stages and by the counters
 * behind it, so the mutations reaching it are too many to be listed one by one - and a list of them is what
 * this replaces. The actions leaving the board alone are listed instead: they are the ones that must keep
 * the snapshot the board has just filled, and everything else drops it. The asymmetry is the whole design -
 * a reset made for nothing costs one recount, a reset missed costs the numbers of the board for a full ttl.
 *
 * @internal
 */
trait DeadlineCountersResetTrait
{
	/**
	 * Action names of this controller that leave the board of the actor as it was: the reads, and the odd
	 * write that provably cannot reach it.
	 *
	 * @return string[]
	 */
	abstract protected function getReadActionNames(): array;

	/**
	 * The engine calls this once the action is over as a whole, so a mutation split in two - an action that
	 * writes and hands the rest over to another controller - is never followed by a board counted between
	 * the halves. An action that throws never gets here: the mutation behind it, if any, expires by ttl.
	 *
	 * It is called for an action that never ran as well - a request turned away by the prefilters arrives
	 * here with nothing done - so a client retrying with a stale token keeps dropping the snapshot of its
	 * own board. What that can cost is bounded: the reset reaches the entries of the acting user and no
	 * one else, a request with nobody authorized behind it drops nothing at all, and the far end of it is
	 * a board counted as if the cache were off - never numbers that are wrong.
	 *
	 * The answer of the action is not looked at. An action ending in an error is not the same as one that
	 * changed nothing: a mutation is saved before its answer is built, and building it re-reads the task -
	 * a delegation that takes the access of the actor away ends in an error over a task already delegated.
	 * Telling the two apart from here is not possible, and the two costs are not equal either: a refusal
	 * dropped by mistake costs one recount, a save missed leaves the board with the numbers of before for
	 * the whole ttl.
	 */
	protected function processAfterAction(Action $action, $result)
	{
		if (!$this->isReadAction($action->getName()))
		{
			$this->clearDeadlineCountersCache();
		}

		return parent::processAfterAction($action, $result);
	}

	/** the engine resolves an action name regardless of its case, and so does the list of the reads */
	private function isReadAction(string $actionName): bool
	{
		$readActions = array_map('mb_strtolower', $this->getReadActionNames());

		return in_array(mb_strtolower($actionName), $readActions, true);
	}

	/**
	 * The reset follows a mutation that is already saved, so a cache subsystem failing here would answer
	 * an error for a change the portal has applied.
	 */
	private function clearDeadlineCountersCache(): void
	{
		try
		{
			$cache = new DeadlineCountersCache();
			if (!$cache->isEnabled())
			{
				return;
			}

			$userId = (int)($this->getCurrentUser()?->getId() ?? 0);
			if ($userId > 0)
			{
				$cache->clearForUser($userId);
			}
		}
		catch (\Throwable)
		{
			// the board is left to expire by ttl, which bounds its staleness anyway
		}
	}
}
