<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Address;

use Bitrix\Crm\Integrity\DuplicateVolatileCriterion;
use Bitrix\Crm\Integrity\Volatile\FieldCategory;
use Bitrix\Main\Event;
use CCrmEntitySelectorHelper;

/**
 * Everything a written or deleted address causes beyond its own row: the volatile duplicate criteria of
 * the owner are reindexed, the aggregate cache of requisite data of the owner is dropped and the domain
 * events are published.
 *
 * Two addressings live side by side here and must not be mixed up. The reindexing and the cache are
 * aimed at the CRM owner the operation was called with, while the payload of the write event carries the
 * row as it is stored - which for a contact and a company means with their requisite as the owner of the
 * record, and for every other owner means with the owner itself. The owner of the requisite of a requisite
 * address is not among the addressees either. Both are the behaviour subscribers and the duplicate index
 * see today.
 *
 * The order inside one operation is fixed: duplicates, then cache, then the event. Subscribers of the
 * write event already read the reindexed state, and swapping the steps would change what they see.
 *
 * The class runs the effects, it does not decide when to run them: the write operation calls them after
 * changing the storage.
 *
 * @internal
 */
final class AddressEffects
{
	private const MODULE_ID = 'crm';

	/** Published after an address is written, with the full set of stored fields. */
	public const EVENT_AFTER_WRITE = 'OnAfterAddressRegister';

	/** Published before an address row is physically deleted, with the key of the record. */
	public const EVENT_BEFORE_DELETE = 'OnAddressUnregister';

	/** Published after a successful deletion, with the key of the record. */
	public const EVENT_AFTER_DELETE = 'OnAfterAddressUnregister';

	/** Published after the default flag of an address is set, with the address type and the record owner. */
	public const EVENT_AFTER_DEFAULT_FLAG_SET = 'OnAfterAddressSetDef';

	/**
	 * @param int $ownerTypeId Type of the CRM owner the operation was called with, not of the record.
	 * @param array<string, mixed> $storedFields Row as it is stored: the record owns the row and the CRM
	 *        owner is in the anchor columns. The default flag travels as a php boolean.
	 */
	public function runAfterWrite(int $ownerTypeId, int $ownerId, array $storedFields): void
	{
		$this->reindexDuplicates($ownerTypeId, $ownerId);
		$this->clearRequisiteDataCache($ownerTypeId, $ownerId);
		$this->publish(self::EVENT_AFTER_WRITE, $storedFields);
	}

	/**
	 * Published while the row is still there on purpose: a subscriber loads the anchor columns by the key
	 * with a query of its own, and after the deletion that query would come back empty.
	 *
	 * @param array<string, int> $addressKey Key of the record, {@see OwnerAddressService::buildAddressKey()}.
	 */
	public function publishBeforeDelete(array $addressKey): void
	{
		$this->publish(self::EVENT_BEFORE_DELETE, $addressKey);
	}

	/**
	 * The duplicate criteria are reindexed whatever the deletion answered, the cache and the event follow
	 * only a successful one.
	 *
	 * @param array<string, int> $addressKey Key of the record.
	 */
	public function runAfterDelete(int $ownerTypeId, int $ownerId, array $addressKey, bool $isDeleted): void
	{
		$this->reindexDuplicates($ownerTypeId, $ownerId);

		if (!$isDeleted)
		{
			return;
		}

		$this->clearRequisiteDataCache($ownerTypeId, $ownerId);
		$this->publish(self::EVENT_AFTER_DELETE, $addressKey);
	}

	/**
	 * Published unconditionally, the storage is not asked whether anything changed.
	 *
	 * @param array<string, int> $addressKey Address type and the owner of the record.
	 */
	public function publishDefaultFlagSet(array $addressKey): void
	{
		$this->publish(self::EVENT_AFTER_DEFAULT_FLAG_SET, $addressKey);
	}

	private function reindexDuplicates(int $ownerTypeId, int $ownerId): void
	{
		DuplicateVolatileCriterion::register($ownerTypeId, $ownerId, [FieldCategory::ADDRESS]);
	}

	private function clearRequisiteDataCache(int $ownerTypeId, int $ownerId): void
	{
		CCrmEntitySelectorHelper::clearPrepareRequisiteDataCacheByEntity($ownerTypeId, $ownerId);
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function publish(string $eventName, array $fields): void
	{
		(new Event(self::MODULE_ID, $eventName, ['fields' => $fields]))->send();
	}
}
