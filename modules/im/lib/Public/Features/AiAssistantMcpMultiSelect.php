<?php
declare(strict_types=1);

namespace Bitrix\Im\Public\Features;

/**
 * Opt-in for choosing several MCP connections in a BitrixGPT chat. The selector itself
 * and the plural-param reader of aiassistant are checked by Features before this flag.
 */
final class AiAssistantMcpMultiSelect extends LegacyFeatureFlag
{
}
