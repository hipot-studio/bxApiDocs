<?php

namespace Bitrix\Crm\Service\Timeline\Item\Factory;

use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Item;
use Bitrix\Crm\Service\Timeline\Item\Model;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\TimelineCapabilityInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\ProviderRegistry;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Timeline\TimelineItemRequest;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\TimelineParticipationInterface;
use Bitrix\Main\DI\ServiceLocator;
use CCrmActivityType;

class ConfigurableActivity
{
	/**
	 * Create timeline item for Activity based on Item\Configurable implementation
	 *
	 * The two items chosen by the legacy type of an activity - a letter and a call - belong to no provider and
	 * are decided here. Historical CRM_DOCUMENT activities are the exception to provider-based card selection:
	 * their provider is gone, but their saved rows remain visible as NotAvailable. Everything else is the
	 * provider's own item: which providers build one is declared by their descriptors in the registry.
	 *
	 * @param int $typeId
	 * @param string $providerId
	 * @param Context $context
	 * @param Model $model
	 *
	 * @return Item|null null when nothing here recognizes the activity, which leaves it to the generic items
	 */
	public static function create(int $typeId, string $providerId, Context $context, Model $model): ?Item
	{
		if ($typeId === CCrmActivityType::Email)
		{
			return new Item\Activity\Email($context, $model);
		}

		if ($typeId === CCrmActivityType::Call)
		{
			return new Item\Activity\Call($context, $model);
		}

		if ($typeId !== CCrmActivityType::Provider)
		{
			return null;
		}
		if ($providerId === 'CRM_DOCUMENT')
		{
			return new Item\NotAvailable($context, $model);
		}

		$provider = self::getProviderBuildingItsOwnItem($providerId);
		if ($provider === null)
		{
			return null;
		}

		$item = $provider->createTimelineItem(new TimelineItemRequest($context, $model));

		return $item instanceof Item ? $item : null;
	}

	/**
	 * The provider of the activity when it builds an item of its own, and null when it does not - which is the
	 * answer for an unknown provider too, and the one the generic items rely on.
	 *
	 * No condition of a provider applies: an activity written while its provider still applied to the portal
	 * keeps the item it has always had, which is why the map is read through the timeline participants and not
	 * through the composition of the registry.
	 */
	private static function getProviderBuildingItsOwnItem(string $providerId): ?TimelineCapabilityInterface
	{
		/** @var TimelineParticipationInterface $registry */
		$registry = ServiceLocator::getInstance()->get(ProviderRegistry::SERVICE_CODE);

		$descriptor = $registry->getTimelineParticipants()[$providerId] ?? null;
		if ($descriptor === null || !$descriptor->getTimelineCapabilities()->providesTimelineItem())
		{
			return null;
		}

		$provider = $registry->getTimelineParticipant($providerId);

		return $provider instanceof TimelineCapabilityInterface ? $provider : null;
	}
}
