<?php

namespace Bitrix\Sign\Contract\Item;

interface ItemWithCrmEntity
{
	public function getCrmId(): int;

	public function getCrmEntityTypeId(): ?int;
}
