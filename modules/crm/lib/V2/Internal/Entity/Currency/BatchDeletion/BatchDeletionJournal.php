<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Currency\BatchDeletion;

final class BatchDeletionJournal
{
	/** @var list<string> */
	private array $codes;

	/** @var array<string, array{success: bool, message: string}> */
	private array $outcomes = [];

	/** @param list<string> $codes */
	public function __construct(array $codes)
	{
		if ($codes === [])
		{
			throw new \InvalidArgumentException('Batch deletion journal cannot be empty');
		}

		$uniqueCodes = [];
		foreach ($codes as $code)
		{
			if (!is_string($code) || preg_match('/^[A-Z]{3}$/D', $code) !== 1 || isset($uniqueCodes[$code]))
			{
				throw new \InvalidArgumentException('Batch deletion journal contains an invalid currency code');
			}

			$uniqueCodes[$code] = true;
		}

		$this->codes = array_values($codes);
	}

	/** @return list<string> */
	public function getCodes(): array
	{
		return $this->codes;
	}

	/** @return array<string, array{success: bool, message: string}> */
	public function getOutcomes(): array
	{
		return $this->outcomes;
	}

	public function hasOutcome(string $code): bool
	{
		return isset($this->outcomes[$code]);
	}

	public function getNextUnresolvedCode(): ?string
	{
		foreach ($this->codes as $code)
		{
			if (!$this->hasOutcome($code))
			{
				return $code;
			}
		}

		return null;
	}

	public function recordSuccess(string $code): void
	{
		$this->recordOutcome($code, true, '');
	}

	public function recordFailure(string $code, string $message): void
	{
		$this->recordOutcome($code, false, $message);
	}

	public function isComplete(): bool
	{
		return count($this->outcomes) === count($this->codes);
	}

	/**
	 * @return array{
	 *     codes: list<string>,
	 *     outcomes: array<string, array{success: bool, message: string}>
	 * }
	 */
	public function toArray(): array
	{
		return [
			'codes' => $this->codes,
			'outcomes' => $this->outcomes,
		];
	}

	/** @param array<string, mixed> $data */
	public static function fromArray(array $data): self
	{
		$codes = $data['codes'] ?? null;
		$outcomes = $data['outcomes'] ?? null;
		if (!is_array($codes) || !is_array($outcomes))
		{
			throw new \InvalidArgumentException('Invalid batch deletion journal data');
		}

		$journal = new self($codes);
		foreach ($outcomes as $code => $outcome)
		{
			if (
				!is_string($code)
				|| !is_array($outcome)
				|| !is_bool($outcome['success'] ?? null)
				|| !is_string($outcome['message'] ?? null)
			)
			{
				throw new \InvalidArgumentException('Invalid batch deletion journal outcome');
			}

			$journal->recordOutcome($code, $outcome['success'], $outcome['message']);
		}

		return $journal;
	}

	private function recordOutcome(string $code, bool $success, string $message): void
	{
		if (!in_array($code, $this->codes, true))
		{
			throw new \InvalidArgumentException('Currency code does not belong to the active chunk');
		}

		if ($this->hasOutcome($code))
		{
			return;
		}

		if (preg_match('//u', $message) !== 1)
		{
			throw new \InvalidArgumentException('Batch deletion outcome message must be valid UTF-8');
		}

		$this->outcomes[$code] = [
			'success' => $success,
			'message' => $message,
		];
	}
}
