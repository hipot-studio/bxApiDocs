<?php

namespace Bitrix\Sign\Engine\ActionFilter;

class RuleWithPayload
{
	public function __construct(
		public string $accessPermission,
		public ?string $itemType = null,
		public ?string $itemIdOrUidRequestKey = null,
		public ?string $identifiedBy = null,
		public ?bool $passes = null,
	) {}
}
