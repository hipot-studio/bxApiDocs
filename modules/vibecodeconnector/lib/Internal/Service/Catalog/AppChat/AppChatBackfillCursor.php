<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat;

final readonly class AppChatBackfillCursor
{
	public int $scanAfterItemId;
	public int $catalogItemId;
	public AppChatMembershipPhase $phase;
	public int $afterUserId;
	public int $retryAttempts;
	public int $chatId;
	public bool $isCompleted;

	public function __construct(
		int $scanAfterItemId = 0,
		int $catalogItemId = 0,
		AppChatMembershipPhase $phase = AppChatMembershipPhase::Audience,
		int $afterUserId = 0,
		int $retryAttempts = 0,
		int $chatId = 0,
		bool $isCompleted = false,
	) {
		$this->scanAfterItemId = max(0, $scanAfterItemId);
		$this->catalogItemId = max(0, $catalogItemId);
		$this->phase = $phase;
		$this->afterUserId = max(0, $afterUserId);
		$this->retryAttempts = max(0, $retryAttempts);
		$this->chatId = max(0, $chatId);
		$this->isCompleted = $isCompleted;
	}

	public function activate(int $catalogItemId): self
	{
		return new self(
			scanAfterItemId: $this->scanAfterItemId,
			catalogItemId: $catalogItemId,
			chatId: 0,
			isCompleted: false,
		);
	}

	public function continueWith(AppChatMembershipPhase $phase, int $afterUserId, int $chatId): self
	{
		return new self(
			scanAfterItemId: $this->scanAfterItemId,
			catalogItemId: $this->catalogItemId,
			phase: $phase,
			afterUserId: $afterUserId,
			chatId: $chatId,
			isCompleted: false,
		);
	}

	public function restartWithChat(int $chatId): self
	{
		return new self(
			scanAfterItemId: $this->scanAfterItemId,
			catalogItemId: $this->catalogItemId,
			phase: AppChatMembershipPhase::Audience,
			afterUserId: 0,
			retryAttempts: 0,
			chatId: $chatId,
			isCompleted: false,
		);
	}

	public function completeActiveItem(): self
	{
		return new self(
			scanAfterItemId: $this->catalogItemId,
			chatId: 0,
			isCompleted: false,
		);
	}

	public function completeScan(): self
	{
		return new self(
			scanAfterItemId: $this->scanAfterItemId,
			isCompleted: true,
		);
	}

	public function recordRetry(): self
	{
		return new self(
			scanAfterItemId: $this->scanAfterItemId,
			catalogItemId: $this->catalogItemId,
			phase: $this->phase,
			afterUserId: $this->afterUserId,
			retryAttempts: $this->retryAttempts + 1,
			chatId: $this->chatId,
			isCompleted: false,
		);
	}

	public function toArray(): array
	{
		return [
			'scanAfterItemId' => $this->scanAfterItemId,
			'catalogItemId' => $this->catalogItemId,
			'phase' => $this->phase->value,
			'afterUserId' => $this->afterUserId,
			'retryAttempts' => $this->retryAttempts,
			'chatId' => $this->chatId,
			'isCompleted' => $this->isCompleted,
		];
	}

	public static function fromArray(array $state): self
	{
		return new self(
			scanAfterItemId: (int)($state['scanAfterItemId'] ?? 0),
			catalogItemId: (int)($state['catalogItemId'] ?? 0),
			phase: AppChatMembershipPhase::tryFrom((string)($state['phase'] ?? ''))
				?? AppChatMembershipPhase::Audience,
			afterUserId: (int)($state['afterUserId'] ?? 0),
			retryAttempts: (int)($state['retryAttempts'] ?? 0),
			chatId: (int)($state['chatId'] ?? 0),
			isCompleted: ($state['isCompleted'] ?? false) === true,
		);
	}
}
