<?php

namespace Bitrix\Sign\Item;

use Bitrix\Sign\Contract\Item;

class Company implements Item
{
	public function __construct(
		public int $id,
		public string $title,
		public ?string $rqInn = null,
		public ?string $registerUrl = null,
		/**
		 * @var array|CompanyProvider[]
		 */
		public array $providers = [],
		/**
		 * Whether the simplified goskey (goskey-lite) is available to connect for this client
		 * (global switch + allowlist). Source of truth for the "connect the new goskey" promo.
		 */
		public bool $goskeyLiteAvailable = false,
	) {}
}
