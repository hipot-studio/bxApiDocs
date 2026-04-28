<?php

namespace Bitrix\Sign\Ui\PlaceholderGrid\Item;

use Bitrix\Sign\Item\Document\Placeholder\PlaceholderCollection;
use Bitrix\Sign\Ui\PlaceholderGrid\SectionType;

class SectionConfig
{
	/**
	 * @param SectionConfig[]|null $subsections
	 */
	public function __construct(
		public readonly SectionType $type,
		public readonly string $titleMessageCode,
		public readonly ?PlaceholderCollection $items = null,
		public readonly ?array $subsections = null,
	)
	{
	}
}

