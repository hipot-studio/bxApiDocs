<?php

namespace Bitrix\Sign\Item\Api\Company;

class RegisterByClientRequest implements \Bitrix\Sign\Contract\Item
{
	public array $providerData;

	public function __construct(
		public string $taxId,
		public string $providerCode,
		string $companyName = '',
		string $externalProviderId = '',
	)
	{
		$this->providerData = [
			'companyName' => $companyName,
			'providerUid' => $externalProviderId,
		];
	}
}
