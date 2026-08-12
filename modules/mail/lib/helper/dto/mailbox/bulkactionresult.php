<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Dto\Mailbox;

final class BulkActionResult implements \JsonSerializable
{
	private array $applied = [];

	private array $skipped = [];

	private array $failed = [];

	public function addApplied(int $id): void
	{
		$this->applied[] = $id;
	}

	public function addSkipped(int $id, string $reason): void
	{
		$this->skipped[] = ['id' => $id, 'reason' => $reason];
	}

	public function addFailed(int $id, string $error): void
	{
		$this->failed[] = ['id' => $id, 'error' => $error];
	}

	/**
	 * @return int[]
	 */
	public function getApplied(): array
	{
		return $this->applied;
	}

	/**
	 * @return array{id: int, reason: string}[]
	 */
	public function getSkipped(): array
	{
		return $this->skipped;
	}

	/**
	 * @return array{id: int, error: string}[]
	 */
	public function getFailed(): array
	{
		return $this->failed;
	}

	public function jsonSerialize(): array
	{
		return [
			'applied' => $this->applied,
			'skipped' => $this->skipped,
			'failed'  => $this->failed,
		];
	}
}
