<?php

namespace Bitrix\Sign\Item\Api\Company;

class GetRequest implements \Bitrix\Sign\Contract\Item
{
	public function __construct(
		public array $taxIds,
		public array $supportedProviders,
		public bool $useProvidersWhereSignerSignFirst = false,
	)
	{
	}
}
