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
		/**
		 * CAP-01: whether the wizard may offer goskey as the representative's signing method.
		 * Independent of the promo flag above: it reports the state of the representative branch on
		 * the signing service, not the ability to connect the simplified goskey.
		 */
		public bool $goskeyRepresentativeSigningAvailable = false,
	) {}
}
