<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Activity;

use Closure;

/**
 * Decisions already taken, for the length of one hit.
 *
 * WHAT THE KEY IS. A decision belongs to a user, an activity and an operation, and all three are in the key.
 * Legacy remembered whether an activity may be updated by the activity alone, so the first answer of the hit
 * was handed out to everybody who asked next - one user's right leaked into another user's question. Here
 * that is not a rule to obey but a shape that cannot be written down: a decision is stored and found under
 * the user and the operation both, and there is no entry an activity alone leads to.
 *
 * HOW LONG IT LIVES. As long as the object does, which is at most the hit that made it - nothing here
 * outlives the process and nothing is written anywhere. A cache handed to several collaborators is shared by
 * them; a collaborator that makes its own keeps its answers to itself.
 *
 * WHAT INVALIDATES IT. One thing only: {@see self::forget()}, by the activity whose bindings changed, for
 * every user and every operation at once. It is called by the step of the pipeline that saved the bindings,
 * because the rights over an activity follow the objects it is bound to and that set has just moved. The
 * repository of the bindings does not call it - the storage layer knows nothing about rights - and there is
 * no way to empty the cache as a whole: a right is forgotten because something about it changed, and
 * something that changed can be named.
 *
 * @internal
 */
final class DecisionCache
{
	/** @var array<int, array<string, AccessDecision>> by activity id, then by user and operation */
	private array $decisions = [];

	/**
	 * The decision for this user, activity and operation: the one taken before, or the one the closure takes
	 * now and this cache remembers.
	 *
	 * @param Closure $decide `function (): AccessDecision` - the order of the decision proper.
	 */
	public function remember(int $userId, int $activityId, Operation $operation, Closure $decide): AccessDecision
	{
		$key = $this->key($userId, $operation);

		$remembered = $this->decisions[$activityId][$key] ?? null;
		if ($remembered !== null)
		{
			return $remembered;
		}

		$decision = $decide();
		$this->decisions[$activityId][$key] = $decision;

		return $decision;
	}

	/**
	 * Forgets every decision about this activity - all users, all operations.
	 *
	 * The one published invalidation, and the granularity is deliberate: what changed is the activity, and
	 * whose question it invalidates is not knowable from the change.
	 */
	public function forget(int $activityId): void
	{
		unset($this->decisions[$activityId]);
	}

	private function key(int $userId, Operation $operation): string
	{
		return $userId . ':' . $operation->value;
	}
}
