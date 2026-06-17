<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\UI\Filter;

use Bitrix\UI\EntitySelector\BaseFilter;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;

/**
 * Hides user items that don't belong to the managed-hierarchy whitelist and strips
 * their personal data from the response payload.
 *
 * Attached to the "user" entity by DepartmentProvider in the onlyManagedHierarchy mode.
 * UserProvider::doSearch fetches matches by full name across the whole company (its
 * registration is not under our control — the frontend auto-adds the user entity as
 * soon as a user item enters the dialog), so without this filter every match outside
 * the manager's subtree would still be serialized to the client with full name, photo,
 * position and customData. Item::jsonSerialize ignores the hidden flag for everything
 * except a single `hidden: true` marker, and Dialog/ItemCollection has no public
 * removal API, so we mark items hidden AND clear every field that carries PII in
 * place. Id and entityId stay so that already-selected/preselected references still
 * resolve.
 */
final class ManagedHierarchyUserFilter extends BaseFilter
{
	public function __construct(array $options = [])
	{
		parent::__construct();

		$userIds = $options['userIds'] ?? [];
		$this->options['userIds'] = is_array($userIds)
			? array_map('intval', $userIds)
			: []
		;
	}

	public function isAvailable(): bool
	{
		return true;
	}

	public function apply(array $items, Dialog $dialog): void
	{
		$allowed = array_flip($this->options['userIds']);

		foreach ($items as $item)
		{
			if (!($item instanceof Item))
			{
				continue;
			}

			if (isset($allowed[(int)$item->getId()]))
			{
				continue;
			}

			$this->scrub($item);
		}
	}

	private function scrub(Item $item): void
	{
		$item
			->setHidden(true)
			->setTitle('')
			->setSubtitle(null)
			->setSupertitle(null)
			->setCaption(null)
			->setLinkTitle(null)
			->setAvatar(null)
			->setLink(null)
			->setBadges([])
			->setCustomData([])
		;

		$item->getAvatarOptions()->clear();
		$item->getCaptionOptions()->clear();
		$item->getBadgesOptions()->clear();
		$item->getNodeOptions()->clear();
		$item->getTagOptions()->clear();
	}
}
