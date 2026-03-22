<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\Userfield\Context;

use Bitrix\Crm\Integration\Analytics\Dictionary;

final class CreateContext
{
	public const DEFAULT = Dictionary::SUB_SECTION_USERFIELD_DEFAULT;
	public const MCP_TOOL = Dictionary::SUB_SECTION_USERFIELD_MCP_TOOL;

	public function __construct(
		public readonly string $createFrom = self::DEFAULT,
	)
	{
	}
}
