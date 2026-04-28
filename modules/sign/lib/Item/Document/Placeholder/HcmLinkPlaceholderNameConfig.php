<?php

namespace Bitrix\Sign\Item\Document\Placeholder;

final class HcmLinkPlaceholderNameConfig
{
	public readonly HcmLinkCompanyCollection $collection;

	public function __construct(
		public readonly string $key,
		public readonly int $party,
	)
	{
		$this->collection = new HcmLinkCompanyCollection();
	}
}
