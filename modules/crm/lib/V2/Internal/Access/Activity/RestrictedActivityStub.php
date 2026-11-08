<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Activity;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Main\ArgumentException;

/**
 * An activity the user may not read, as much of it as a reader may show: its id, and the fact that the rest
 * is out of reach.
 *
 * WHAT IT DOES NOT CARRY IS THE POINT. Not the subject, not the description, not the bindings, not the
 * communications, not the files, not the responsible person, not a single date - nothing a reader could put
 * on a screen or answer a question with. The activity of a client the user has no rights over hides what it
 * is about and whom it is with, and hiding it by having nothing to hide is stronger than hiding it by
 * remembering to. The same shape as the stub of the Item provider
 * ({@see \Bitrix\Crm\V2\Public\Provider\Item\AbstractItemProvider}), which keeps the id and drops the rest.
 *
 * BEING RESTRICTED IS A TYPE HERE, not a flag: the model of an activity is final and carries no such flag,
 * so a stub is told from an activity by what it is. A reader over a set gets a list of both
 * ({@see ActivityAccess::readable()}) and asks `instanceof` where it needs to tell them apart.
 *
 * @internal
 */
final class RestrictedActivityStub
{
	private readonly int $activityId;

	/**
	 * @throws ArgumentException on an id no activity can have: a placeholder of nothing is nothing to show.
	 */
	public function __construct(int $activityId)
	{
		if ($activityId <= 0)
		{
			throw new ArgumentException("A restricted activity needs an id of its own, got {$activityId}");
		}

		$this->activityId = $activityId;
	}

	public function getId(): int
	{
		return $this->activityId;
	}

	/**
	 * The stub as the model of the domain, for a reader typed on it: an activity with its id and every other
	 * field unset.
	 *
	 * Unset rather than emptied on purpose. The model tells "not known" from "empty", and a stub knows
	 * nothing: an empty list of communications would state that the activity has none, which is a statement
	 * about data the user may not have.
	 */
	public function toActivity(): Activity
	{
		return (new Activity())->internalSet(Activity::id, $this->activityId);
	}
}
