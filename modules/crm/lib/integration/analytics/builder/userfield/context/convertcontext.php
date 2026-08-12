<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\Userfield\Context;

use CCrmOwnerType;

final class ConvertContext
{
	public const SOURCE_MANUAL = 'manual';

	public int $sourceEntityTypeId = CCrmOwnerType::Undefined;

	public function setSourceEntityTypeId(int $entityTypeId): self
	{
		$this->sourceEntityTypeId = $entityTypeId;

		return $this;
	}
}
