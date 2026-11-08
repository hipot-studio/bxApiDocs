<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Provider\Timeline;

use Bitrix\Crm\Activity\Provider;
use Bitrix\Crm\Activity\ProviderId;
use Bitrix\Crm\Service\Timeline\Item;
use Bitrix\Crm\Service\Timeline\Item\Activity\StoreDocument\NotEnoughGoodsInStock;

/**
 * The card of an activity of a provider still written as a legacy class.
 *
 * THE ONE PLACE THE CHOICE OF A CARD MOVES TO. A provider still served by legacy code has no method to build
 * its card with and is not given one - the wrapper around it answers for it and comes here. So this is where
 * the two dozen branches live, along with the three of them that are more than a lookup: the card of a stock
 * document depends on whether goods or services ran short, the card of a conference on whether it was started
 * or joined, and a provider that can be switched off portal-wide gets the card saying so instead of its own.
 *
 * NOTHING BUT THE CHOICE MOVES. The cards themselves are untouched, and so are the legacy provider classes -
 * the two states a card depends on are still read where they were read before: the type of a provider activity
 * and the data the provider stored both come from the timeline model, and whether a provider is switched on is
 * still asked of the legacy class.
 *
 * IT IS THE ONLY COPY OF THAT CHOICE. The factory the timeline asks holds none of these branches any more: it
 * decides the two cards belonging to no provider and asks the provider for the rest.
 *
 * IT GAINS NO NEW BRANCHES. A provider rewritten natively builds its own card, and this class shrinks as they
 * are rewritten one by one until nothing is left of it.
 *
 * THE TWO CARDS CHOSEN BY THE LEGACY TYPE of an activity - a letter and a call - are not here. They belong to
 * no provider and stay with the caller, which decides them before asking any provider at all.
 *
 * Normative shape: TPL-10, map Nr8 of LBS-2 section 3.
 *
 * @internal
 */
final class LegacyTimelineItemFactory
{
	/**
	 * The card of one activity of the given provider, or null when the provider has none of its own and the
	 * generic cards are to render the activity.
	 */
	public function createTimelineItem(string $providerId, TimelineItemRequest $request): ?Item
	{
		$className = $this->resolveItemClassName($providerId, $request);
		if ($className === null)
		{
			return null;
		}

		return new $className($request->getContext(), $request->getModel());
	}

	/**
	 * @return class-string<Item>|null
	 */
	private function resolveItemClassName(string $providerId, TimelineItemRequest $request): ?string
	{
		$byProviderId = $this->itemClassNameByProviderId();
		if (isset($byProviderId[$providerId]))
		{
			return $byProviderId[$providerId];
		}

		$ofSwitchableProvider = $this->itemClassNameOfSwitchableProvider($providerId);
		if ($ofSwitchableProvider !== null)
		{
			return $ofSwitchableProvider;
		}

		if ($providerId === Provider\StoreDocument::getId())
		{
			return $this->itemClassNameOfStoreDocument($request);
		}

		if ($providerId === Provider\Zoom::getId())
		{
			return $this->itemClassNameOfZoom($request);
		}

		if ($providerId === Provider\ConfigurableRestApp::getId())
		{
			return Item\Activity\ConfigurableRestApp::isModelValid($request->getModel())
				? Item\Activity\ConfigurableRestApp::class
				: Item\NotAvailable::class
			;
		}

		return null;
	}

	/**
	 * The providers whose card is a lookup and nothing more.
	 *
	 * @return array<string, class-string<Item>>
	 */
	private function itemClassNameByProviderId(): array
	{
		return [
			ProviderId::IMOPENLINES_SESSION => Item\Activity\OpenLine::class,
			Provider\ToDo\ToDo::getId() => Item\Activity\ToDo::class,
			Provider\Payment::getId() => Item\Activity\Payment::class,
			Provider\Delivery::getId() => Item\Activity\Delivery::class,
			Provider\Sms::getId() => Item\Activity\Sms\Sms::class,
			Provider\Telegram::getId() => Item\Activity\Sms\Telegram::class,
			Provider\WhatsApp::getId() => Item\Activity\Sms\Whatsapp::class,
			Provider\Notification::getId() => Item\Activity\Sms\Notification::class,
			Provider\CalendarSharing::getId() => Item\Activity\CalendarSharing::class,
			Provider\Tasks\Comment::getId() => Item\Activity\Tasks\Comment::class,
			Provider\Tasks\Task::getId() => Item\Activity\Tasks\Task::class,
			Provider\Visit::getId() => Item\Activity\Visit::class,
			Provider\Booking\Booking::getId() => Item\Activity\Booking\Booking::class,
			Provider\Booking\WaitListItem::getId() => Item\Activity\Booking\WaitListItem::class,
			Provider\Bizproc\Workflow::getId() => Item\Activity\Bizproc\WorkflowCompleted::class,
			Provider\Bizproc\Comment::getId() => Item\Activity\Bizproc\CommentAdded::class,
			Provider\Bizproc\Task::getId() => Item\Activity\Bizproc\Task::class,
			Provider\RepeatSale::getId() => Item\Activity\RepeatSale::class,
			Provider\EntityExclusion::getId() => Item\Activity\EntityExclusion::class,
			Provider\Sync::getId() => Item\Activity\Sync::class,
		];
	}

	/**
	 * The providers that can be switched off portal-wide: with one of them off, the activity gets the card
	 * saying the feature is not available. Null means the provider is not one of them.
	 *
	 * @return class-string<Item>|null
	 */
	private function itemClassNameOfSwitchableProvider(string $providerId): ?string
	{
		if ($providerId === Provider\SignDocument::getId())
		{
			return Provider\SignDocument::isActive()
				? Item\Activity\SignDocument::class
				: Item\NotAvailable::class
			;
		}

		if ($providerId === Provider\SignB2eDocument::getId())
		{
			return Provider\SignB2eDocument::isActive()
				? Item\Activity\SignB2eDocument::class
				: Item\NotAvailable::class
			;
		}

		return null;
	}

	/**
	 * Whether goods or services ran short decides the card of a stock document. A type of its own that means
	 * neither leaves the activity to the generic cards.
	 *
	 * @return class-string<Item>|null
	 */
	private function itemClassNameOfStoreDocument(TimelineItemRequest $request): ?string
	{
		$providerTypeId = $request->getModel()->getAssociatedEntityModel()?->get('PROVIDER_TYPE_ID');

		$byProviderTypeId = [
			Provider\StoreDocument::PROVIDER_TYPE_ID_PRODUCT => NotEnoughGoodsInStock\Product::class,
			Provider\StoreDocument::PROVIDER_TYPE_ID_SERVICE => NotEnoughGoodsInStock\Service::class,
		];

		return $byProviderTypeId[$providerTypeId] ?? null;
	}

	/**
	 * A conference joined is a log entry rather than an activity card, and the provider says which of the two
	 * happened in the data it stored.
	 *
	 * @return class-string<Item>
	 */
	private function itemClassNameOfZoom(TimelineItemRequest $request): string
	{
		$providerData = $request->getModel()->getHistoryItemModel()?->get('PROVIDER_DATA') ?? [];
		if (($providerData['ZOOM_EVENT_TYPE'] ?? '') === Provider\Zoom::TYPE_ZOOM_CONF_JOINED)
		{
			return Item\LogMessage\Zoom\ConferenceJoined::class;
		}

		return Item\Activity\Zoom::class;
	}
}
