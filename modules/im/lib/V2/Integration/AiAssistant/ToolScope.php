<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant;

use Bitrix\Im\Public\Features\McpReadTools;
use Bitrix\Im\Public\Features\McpWriteTools;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Feature\Context;

/**
 * Read/write scope of the IM MCP tools.
 *
 * Single source of truth for the gating feature flags, shared by the toolset-level
 * checks in ImToolSet and the tool-level checks in BaseImTool.
 */
enum ToolScope
{
	case Read;
	case Write;

	public function isEnabled(int $userId): bool
	{
		$flag = match ($this)
		{
			self::Read => McpReadTools::class,
			self::Write => McpWriteTools::class,
		};

		return Feature::isEnabled($flag, new Context($userId));
	}
}
