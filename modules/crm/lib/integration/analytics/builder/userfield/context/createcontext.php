<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\Userfield\Context;

use Bitrix\Crm\Integration\Analytics\Dictionary;

final class CreateContext
{
	public const SOURCE_DEFAULT = Dictionary::UNKNOWN;
	public const SOURCE_MCP_TOOL = 'ai';
	public const SOURCE_MANUAL = 'manual';

	public function __construct(
		public readonly string $source = self::SOURCE_DEFAULT,
	)
	{
	}
}
