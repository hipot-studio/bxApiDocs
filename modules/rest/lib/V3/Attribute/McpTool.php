<?php

namespace Bitrix\Rest\V3\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class McpTool extends AbstractAttribute
{
	public function __construct(
		public readonly bool $export = true,
		public readonly string $toolSetCode = '',
		public readonly ?string $nameOverride = null,
		public readonly ?string $descriptionOverride = null,
		public readonly array $descriptionAdditions = [],
		public readonly array $hiddenFields = [],
		public readonly ?array $fieldWhitelist = null,
	)
	{
	}
}
