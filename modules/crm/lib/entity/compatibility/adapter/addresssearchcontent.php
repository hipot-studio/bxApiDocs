<?php

namespace Bitrix\Crm\Entity\Compatibility\Adapter;

use Bitrix\Crm\Entity\Compatibility\Adapter;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Result;

/**
 * Marks the search content of an entity whose address is a part of it for the deferred build after the address
 * is saved. The address is not a field of the item, so the operation does not see its change; the add is marked
 * by the operation itself, and the deferred build reads the address saved by then.
 * Must be added after the address adapter.
 */
final class AddressSearchContent extends Adapter
{
	public function __construct(
		private readonly Address $addressAdapter,
		private readonly int $entityTypeId,
	)
	{
	}

	protected function doPerformAdd(array &$fields, array $compatibleOptions): Result
	{
		return new Result();
	}

	protected function doPerformUpdate(int $id, array &$fields, array $compatibleOptions): Result
	{
		if ($id > 0 && $this->addressAdapter->isChangedByUpdate($fields))
		{
			Container::getInstance()->getDeferredSearchIndexer()->mark($this->entityTypeId, $id);
		}

		return new Result();
	}

	protected function doPerformDelete(int $id, array $compatibleOptions): Result
	{
		return new Result();
	}
}
