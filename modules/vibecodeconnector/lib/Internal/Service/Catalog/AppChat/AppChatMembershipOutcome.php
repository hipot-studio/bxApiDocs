<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat;

final class AppChatMembershipOutcome
{
	private function __construct(
		public readonly int $added,
		public readonly ?AppChatMembershipPhase $nextPhase,
		public readonly ?int $nextCursor,
		public readonly ?string $retryReason,
		public readonly ?AppChatMembershipRetryScope $retryScope,
		public readonly int $chatId,
		public readonly bool $restartRequired,
	) {}

	public static function completed(int $added = 0, int $chatId = 0): self
	{
		return new self($added, null, null, null, null, max(0, $chatId), false);
	}

	public static function continued(
		int $added,
		AppChatMembershipPhase $nextPhase,
		int $nextCursor,
		int $chatId = 0,
	): self
	{
		return new self($added, $nextPhase, max(0, $nextCursor), null, null, max(0, $chatId), false);
	}

	public static function advanced(int $nextCursor, int $added = 0): self
	{
		return new self($added, null, max(0, $nextCursor), null, null, 0, false);
	}

	public static function retryable(
		string $reason,
		int $added = 0,
		int $chatId = 0,
		AppChatMembershipRetryScope $retryScope = AppChatMembershipRetryScope::ItemSpecific,
	): self
	{
		return new self($added, null, null, $reason, $retryScope, max(0, $chatId), false);
	}

	public static function restartWithChat(int $chatId): self
	{
		return new self(
			0,
			AppChatMembershipPhase::Audience,
			0,
			null,
			null,
			max(0, $chatId),
			true,
		);
	}

	public function isComplete(): bool
	{
		return $this->nextCursor === null && $this->retryReason === null;
	}
}
