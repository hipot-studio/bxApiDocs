<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

/**
 * One-shot in-memory right of an admitted open send. It is never persisted nor restored: a process
 * that loses it leaves the debt of its send in the durable state for good.
 */
final class OpenSendRight
{
	private bool $consumed = false;

	public function __construct(
		private readonly string $openToken,
		private readonly string $hostDocKey,
		private readonly int $documentSessionId,
		private readonly bool $accounted,
	)
	{
	}

	public function getOpenToken(): string
	{
		return $this->openToken;
	}

	public function getHostDocKey(): string
	{
		return $this->hostDocKey;
	}

	public function getDocumentSessionId(): int
	{
		return $this->documentSessionId;
	}

	/** False for a legacy attempt without a durable state: its sends are never written off. */
	public function isAccounted(): bool
	{
		return $this->accounted;
	}

	/** Returns true exactly once; the caller must consume the right before its first DB attempt. */
	public function consume(): bool
	{
		if ($this->consumed)
		{
			return false;
		}
		$this->consumed = true;

		return true;
	}
}
