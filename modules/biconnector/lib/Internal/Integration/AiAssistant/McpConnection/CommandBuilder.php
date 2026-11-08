<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\McpConnection;

use Bitrix\BIConnector\Internal\Integration\AiAssistant\McpConnection\Dto\GatewayResult;

/**
 * Builds the client command the user copies to register the BI connection.
 *
 * Only the command shell, the connection name and the transport map are fixed here: the address,
 * the authorization scheme and the secret are taken from the contour answer as they came and are
 * quoted for the shell that will run the command, so that the shell reads them as data.
 */
final class CommandBuilder
{
	private const CONNECTION_NAME = 'bitrix24-bi';

	/**
	 * The contour names its transport in its own words, while the client flag accepts stdio, sse or
	 * http, where streamable HTTP is http. An unknown transport is passed through untouched: a
	 * visibly wrong command is better than a plausible one that connects nowhere.
	 */
	private const TRANSPORT_FLAGS = [
		'streamable-http' => 'http',
	];

	/**
	 * @param GatewayResult $issued Answer of the issue or reissue call, the source of the server block.
	 * @param string $token The secret returned by that same call.
	 * @return string|null Null when the answer carries no address; the caller turns that into
	 * the BI_MCP_SERVER_URL_MISSING refusal.
	 */
	public static function build(GatewayResult $issued, string $token): ?string
	{
		$url = $issued->getServerUrl();
		if ($url === null || trim($url) === '')
		{
			return null;
		}

		$transport = (string)$issued->getServerTransport();
		$authorization = (string)$issued->getServerAuthorization();

		return 'claude mcp add --transport ' . self::quote(self::TRANSPORT_FLAGS[$transport] ?? $transport)
			. ' ' . self::CONNECTION_NAME
			. ' ' . self::quote($url)
			. ' --header ' . self::quote('Authorization: ' . $authorization . ' ' . $token)
		;
	}

	/**
	 * Wraps a value in POSIX single quotes, the only quoting form where the shell expands nothing.
	 * An inner single quote is closed, escaped and reopened, so the value reaches the client intact.
	 */
	private static function quote(string $value): string
	{
		return "'" . str_replace("'", "'\\''", $value) . "'";
	}
}
