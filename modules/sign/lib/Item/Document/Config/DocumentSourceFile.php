<?php

namespace Bitrix\Sign\Item\Document\Config;

use Bitrix\Sign\Contract;

class DocumentSourceFile implements Contract\Item
{
	public function __construct(
		public string $name,
		public string $type,
		public string $content,
	)
	{}
}
