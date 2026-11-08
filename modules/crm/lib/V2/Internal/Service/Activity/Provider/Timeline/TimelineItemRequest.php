<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Provider\Timeline;

use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Item\Model;

/**
 * What a provider is given when it is asked for the card of one of its activities: the surface the card is
 * being built for, and what the timeline already knows about the activity.
 *
 * NEITHER THE IDENTIFIER OF THE PROVIDER NOR THE TYPE OF THE ACTIVITY IS HERE. The provider being asked is the
 * one answering, so its own identifier tells it nothing new; and the two cards chosen by the legacy type of an
 * activity - a letter and a call - are chosen before any provider is asked, so that type never reaches here.
 *
 * WHATEVER ELSE A CARD DEPENDS ON IS READ OUT OF THE MODEL. The type of a provider activity and the data the
 * provider itself stored are both held by the timeline model; the columns of the activity are not a second
 * source for them and must not become one.
 *
 * A REQUEST RATHER THAN A PAIR OF ARGUMENTS. Every provider that builds a card of its own implements the same
 * method, so what a card is built from has to be able to grow without every one of those implementations
 * changing shape. Normative shape: TPL-10.
 *
 * The two types belong to the timeline layer of this very module, which is the one exception to keeping foreign
 * types out of the contracts here: no integration layer stands between the CRM and its own timeline.
 *
 * @internal
 */
final class TimelineItemRequest
{
	public function __construct(
		private readonly Context $context,
		private readonly Model $model,
	)
	{
	}

	/**
	 * The surface the card is being built for.
	 */
	public function getContext(): Context
	{
		return $this->context;
	}

	/**
	 * What the timeline knows about the activity the card is for.
	 */
	public function getModel(): Model
	{
		return $this->model;
	}
}
