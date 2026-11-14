<?php

namespace Bitrix\Rest\V3\Mcp\Execution;

use Bitrix\Main\HttpRequest;

/**
 * The request of a tool call carries the tool arguments only: php://input belongs to the outer HTTP request
 * of the MCP transport, and Request::create() reads it whenever the arguments are empty.
 */
final class InProcessHttpRequest extends HttpRequest
{
	public static function getInput(): string
	{
		return '';
	}
}
