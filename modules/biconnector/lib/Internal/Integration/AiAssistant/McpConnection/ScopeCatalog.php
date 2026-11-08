<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\McpConnection;

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Turns the access scope code of the contour into the readable list of BI tool sets behind it.
 *
 * The sets themselves carry no name fit for a human - a code and an English description for the
 * model is all they have - so their names live here as BI phrases.
 */
final class ScopeCatalog
{
	public const BI_SCOPE = 'mcp.bi';

	/**
	 * Keys are the codes the tool set classes answer with, without the module prefix the contour
	 * adds in its own list of identifiers.
	 */
	private const TOOL_SETS = [
		'bi_dashboards' => 'BIC_MCP_CONNECTION_SCOPE_BI_DASHBOARDS',
		'bi_charts' => 'BIC_MCP_CONNECTION_SCOPE_BI_CHARTS',
		'bi_datasets' => 'BIC_MCP_CONNECTION_SCOPE_BI_DATASETS',
		'bi_tables' => 'BIC_MCP_CONNECTION_SCOPE_BI_TABLES',
	];

	/**
	 * @param array $scopeCodes Scope codes as the contour returned them.
	 * @return string[] Readable tool set names; empty for a scope the catalog does not know, which
	 * keeps an unknown scope from failing the operation it describes.
	 */
	public function getNames(array $scopeCodes): array
	{
		if (!in_array(self::BI_SCOPE, $scopeCodes, true))
		{
			return [];
		}

		return array_values(array_map(
			static fn(string $phraseId): string => (string)Loc::getMessage($phraseId),
			self::TOOL_SETS,
		));
	}

	/**
	 * @return string[] Tool set codes of the BI scope, for the test that compares them with the sets
	 * the module actually declares.
	 */
	public function getToolSetCodes(): array
	{
		return array_keys(self::TOOL_SETS);
	}
}
