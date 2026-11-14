<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Loader;

use Bitrix\Crm\Binding\ContactCompanyTable;
use Bitrix\Crm\Binding\DealContactTable;
use Bitrix\Crm\Binding\EntityContactTable;
use Bitrix\Crm\Binding\LeadContactTable;
use Bitrix\Crm\Binding\QuoteContactTable;
use Bitrix\Crm\V2\Public\Entity\Item\ContactBinding;
use Bitrix\Crm\V2\Public\Entity\Item\ContactBindingCollection;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;

/**
 * Loads `contactBindings` (the contacts attached to a Deal / Lead / Quote / Company / smart-* item)
 * onto V2 Items.
 *
 * The legacy storage tables are entity-specific:
 *
 * - Deal   → `b_crm_deal_contact`        (DealContactTable)
 * - Lead   → `b_crm_lead_contact`        (LeadContactTable)
 * - Quote  → `b_crm_quote_contact`       (QuoteContactTable)
 * - Company → `b_crm_contact_company`    (ContactCompanyTable, traversed reverse)
 * - smart-* → `b_crm_entity_contact`     (EntityContactTable, the unified table for SPA)
 *
 * Always installs a (possibly empty) collection — null means "not loaded".
 *
 * @internal
 */
final class ContactBindingLoader
{
	/**
	 * @param Item[] $items
	 */
	public function load(array $items, ItemSelect $select): void
	{
		if (!$select->shouldLoadContactBindings())
		{
			return;
		}

		$persisted = array_values(array_filter($items, static fn(Item $i) => $i->getId() !== null));
		if (empty($persisted))
		{
			return;
		}

		$entityType = $persisted[0]->getEntityType();
		$ownerIds = array_map(static fn(Item $i) => (int)$i->getId(), $persisted);

		$bindingsByOwner = $this->fetchBulk($entityType, $ownerIds);

		foreach ($persisted as $item)
		{
			$collection = new ContactBindingCollection();
			foreach ($bindingsByOwner[(int)$item->getId()] ?? [] as $row)
			{
				$collection->add(new ContactBinding(
					contactId: (int)$row['CONTACT_ID'],
					sort: (int)($row['SORT'] ?? 0),
					isPrimary: ((string)($row['IS_PRIMARY'] ?? 'N')) === 'Y',
				));
			}

			$item->internalSet(Item::contactBindings, $collection);
		}
	}

	/**
	 * @param int[] $ownerIds
	 * @return array<int, array<int, array{CONTACT_ID:int, SORT:int, IS_PRIMARY:string}>> ownerId => rows
	 */
	private function fetchBulk(EntityType $entityType, array $ownerIds): array
	{
		return match ($entityType->getId())
		{
			OwnerType::DEAL => DealContactTable::getBulkDealBindings($ownerIds),
			OwnerType::LEAD => LeadContactTable::getBulkLeadBindings($ownerIds),
			OwnerType::QUOTE => QuoteContactTable::getBulkQuoteBindings($ownerIds),
			OwnerType::COMPANY => ContactCompanyTable::getBulkCompanyBindings($ownerIds),
			default => $this->fetchSmartBindings($entityType->getId(), $ownerIds),
		};
	}

	/**
	 * @param int[] $ownerIds
	 * @return array<int, array<int, array{CONTACT_ID:int, SORT:int, IS_PRIMARY:string}>>
	 */
	private function fetchSmartBindings(int $entityTypeId, array $ownerIds): array
	{
		$bucket = [];
		foreach ($ownerIds as $id)
		{
			$bucket[(int)$id] = [];
		}

		$rows = EntityContactTable::query()
			->setSelect(['ENTITY_ID', 'CONTACT_ID', 'SORT', 'IS_PRIMARY'])
			->where('ENTITY_TYPE_ID', $entityTypeId)
			->whereIn('ENTITY_ID', $ownerIds)
			->setOrder(['ENTITY_ID' => 'ASC', 'SORT' => 'ASC'])
			->fetchAll()
		;

		foreach ($rows as $row)
		{
			$bucket[(int)$row['ENTITY_ID']][] = [
				'CONTACT_ID' => (int)$row['CONTACT_ID'],
				'SORT' => (int)($row['SORT'] ?? 0),
				// The column is read back as the string it is stored as, so the flag is compared and not cast:
				// casting would read the stored 'N' as true and make every binding the primary one.
				'IS_PRIMARY' => ((string)($row['IS_PRIMARY'] ?? 'N')) === 'Y' ? 'Y' : 'N',
			];
		}

		return $bucket;
	}
}
