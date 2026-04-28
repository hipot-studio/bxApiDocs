<?php

namespace Bitrix\Sign\Item\Api\Document;

use Bitrix\Sign\Contract;
use Bitrix\Sign\Type\Document\Version;

class RegisterRequest implements Contract\Item
{
	public function __construct(
		public string $lang,
		public string $scenario,
		public ?string $title = null,
		public int $version = Version::CURRENT,
		public bool $hasPlaceholders = false,
	) {}
}
