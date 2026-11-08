<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\McpConnection;

use Bitrix\BIConnector\Internal\Integration\AiAssistant\McpConnection\Dto\GatewayResult;

/**
 * Port to the aiassistant MCP connection contour: the only way the module talks to it.
 *
 * No method throws: an unreachable or refusing contour comes back as an unsuccessful
 * {@see GatewayResult} carrying the contour refusal code.
 */
interface McpTokenGatewayInterface
{
	/**
	 * Whether the contour is present on this portal at all.
	 */
	public function isAvailable(): bool;

	/**
	 * Read-only connection state of the current user.
	 */
	public function getState(): GatewayResult;

	/**
	 * Issues the token unless the current user already has an alive one.
	 */
	public function issue(): GatewayResult;

	/**
	 * Replaces the alive token of the current user with a new one.
	 */
	public function reissue(): GatewayResult;
}
