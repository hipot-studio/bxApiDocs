<?php

namespace Bitrix\Sign\Item\Document\Placeholder;

use Bitrix\Sign\Contract;

class HcmLinkCompany implements Contract\Item
{
	public function __construct(
		public string $hcmLinkTitle,
		public string $myCompanyTitle,
		public PlaceholderCollection $items,
	)
	{
	}
}
