<?php

namespace Bitrix\Sign\Item\Document\Placeholder;

use Bitrix\Sign\Contract;

class HcmLinkPlaceholders implements Contract\Item
{
	public function __construct(
		public HcmLinkCompanyCollection $employee,
		public HcmLinkCompanyCollection $representative,
	)
	{
	}
}
