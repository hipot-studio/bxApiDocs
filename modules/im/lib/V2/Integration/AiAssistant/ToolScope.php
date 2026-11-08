<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant;

use Bitrix\Main\Config\Option;

/**
 * Read/write scope of the IM MCP tools.
 *
 * Single source of truth for the gating options, shared by the toolset-level
 * checks in ImToolSet and the tool-level checks in BaseImTool.
 */
enum ToolScope
{
	case Read;
	case Write;

	public function isEnabled(): bool
	{
		return Option::get('im', $this->getOptionName(), 'Y') === 'Y';
	}

	private function getOptionName(): string
	{
		return match ($this)
		{
			self::Read => 'mcp_read_tools_enabled',
			self::Write => 'mcp_write_tools_enabled',
		};
	}
}
