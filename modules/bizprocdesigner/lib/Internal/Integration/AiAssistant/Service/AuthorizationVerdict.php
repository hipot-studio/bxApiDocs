<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

/**
 * The decision of any reverse-entry barrier - subject-based ({@see OperationAuthorizationGuard}) or
 * resource-based ({@see CreateStorageNodePolicy}) alike. The verdict carries no machine code: one refusal is
 * told apart from another by its reason alone, and whether that reason reaches the agent is decided by the
 * barrier, not here - a document-access reason names another user's template and stays internal, while a
 * create-node reason is written to be read by the agent and corrected.
 */
final class AuthorizationVerdict
{
	private function __construct(
		public readonly bool $allowed,
		public readonly ?string $reason = null,
	) {}

	public static function allow(): self
	{
		return new self(true);
	}

	public static function reject(string $reason): self
	{
		return new self(false, $reason);
	}
}
