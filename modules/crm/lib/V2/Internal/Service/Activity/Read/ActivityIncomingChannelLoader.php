<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Read;

use Bitrix\Crm\Activity\IncomingChannel;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;

/**
 * Fills the incoming-channel sign of a set of activities out of the table that owns it.
 *
 * The sign is not a column of the activity: `b_crm_act_incoming_channel` holds one row per activity
 * registered as an incoming channel, and the absence of the row is the answer "no". The direction does not
 * give it either - an outgoing call of a channel is still a channel, and an incoming call outside one is not.
 *
 * One query per set, and none at all for an empty one.
 *
 * @internal
 */
final class ActivityIncomingChannelLoader
{
	private readonly IncomingChannel $incomingChannel;

	/**
	 * @param IncomingChannel|null $incomingChannel the owner of the table; one instance is kept for the
	 *        lifetime of the loader, because building one subscribes to the events of that table.
	 */
	public function __construct(?IncomingChannel $incomingChannel = null)
	{
		$this->incomingChannel = $incomingChannel ?? IncomingChannel::getInstance();
	}

	/**
	 * Hydrates {@see Activity::isIncomingChannel()} of every given activity. The sign is always a boolean: an
	 * activity with no row of its own is not an incoming channel.
	 */
	public function fill(Activity ...$activities): void
	{
		$registered = $this->registeredIds($this->idsOf($activities));

		foreach ($activities as $activity)
		{
			$activity->internalSet(Activity::isIncomingChannel, isset($registered[(int)$activity->getId()]));
		}
	}

	/**
	 * @param int[] $activityIds
	 * @return array<int, true> the registered ids as a set
	 */
	private function registeredIds(array $activityIds): array
	{
		if ($activityIds === [])
		{
			return [];
		}

		$registered = [];
		foreach ($this->incomingChannel->getIncomingChannelActivityIds($activityIds) as $activityId)
		{
			$registered[(int)$activityId] = true;
		}

		return $registered;
	}

	/**
	 * @param Activity[] $activities
	 * @return int[]
	 */
	private function idsOf(array $activities): array
	{
		$ids = [];
		foreach ($activities as $activity)
		{
			$id = (int)$activity->getId();
			if ($id > 0)
			{
				$ids[$id] = $id;
			}
		}

		return array_values($ids);
	}
}
