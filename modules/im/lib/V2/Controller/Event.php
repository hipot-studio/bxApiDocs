<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Controller;

use Bitrix\Im\V2\EventLog\SubscriptionService;
use Bitrix\Im\V2\EventLog\EventService;
use Bitrix\Main\Engine\CurrentUser;

/**
 * @restMethod im.v2.Event.*
 */
class Event extends BaseController
{
	/**
	 * Subscribe the current user to event logging.
	 * After subscribing, message events will be recorded in b_im_event_log
	 * and can be fetched via im.v2.Event.get.
	 *
	 * @restMethod im.v2.Event.subscribe
	 */
	public function subscribeAction(CurrentUser $currentUser): bool
	{
		$userId = (int)$currentUser->getId();
		(new SubscriptionService())->subscribe($userId);

		return true;
	}

	/**
	 * Unsubscribe the current user from event logging.
	 * Stops recording events; existing unprocessed events remain
	 * until they expire (24h) or are acknowledged via offset.
	 *
	 * @restMethod im.v2.Event.unsubscribe
	 */
	public function unsubscribeAction(CurrentUser $currentUser): bool
	{
		$userId = (int)$currentUser->getId();
		(new SubscriptionService())->unsubscribe($userId);

		return true;
	}

	/**
	 * Fetch pending events for the current user.
	 * Pass offset to acknowledge (delete) previously fetched events.
	 *
	 * @restMethod im.v2.Event.get
	 */
	public function getAction(
		CurrentUser $currentUser,
		int $offset = 0,
		int $limit = 100,
	): ?array
	{
		$userId = (int)$currentUser->getId();

		return (new EventService())->fetchEventsForRest([$userId], $offset, $limit);
	}
}
