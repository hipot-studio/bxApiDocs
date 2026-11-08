<?php

namespace Bitrix\BIConnector\Access\TableGroup;

final class TableGroup extends EO_TableGroup
{
	public function isSystem(): bool
	{
		return $this->getType() === TableGroupTable::GROUP_TYPE_SYSTEM;
	}
}
