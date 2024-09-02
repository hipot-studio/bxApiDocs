<?php

declare(strict_types = 1);

namespace Bitrix\AI\Synchronization;

use Bitrix\AI\Model\RoleTable;

class RoleSync extends BaseSync
{
	/**
	 * @inheritDoc
	 */
	protected function getDataManager(): RoleTable
	{
		return $this->dataManager ?? ($this->dataManager = new RoleTable());
	}
}
