<?php

namespace Bitrix\Sign\Item\Document\Config;

use Bitrix\Main\Type\DateTime;
use Bitrix\Sign\Contract;

class DocumentExternalSettings implements Contract\Item
{
	public function __construct(
		public ?string $externalId,
		public ?DateTime $externalDateCreate,
	)
	{
	}
}
