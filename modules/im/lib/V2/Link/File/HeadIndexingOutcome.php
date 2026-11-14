<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Link\File;

final class HeadIndexingOutcome
{
	public const ATTEMPTS = 'attempts';
	public const CREATED = 'created';
	public const UPDATED = 'updated';
	public const NO_OP = 'no-op';
	public const SKIPPED = 'skipped';
	public const ERRORS = 'errors';
	public const CAPPED = 'capped';
	public const FLAG_OFF = 'flag-off';
	public const INVALID_STORAGE = 'invalid-storage';
	public const FOREIGN_STORAGE = 'foreign-storage';

	public const DEFAULT_LIMIT = 10;
	public const MAX_LIMIT = 100;

	private array $counters = [
		self::ATTEMPTS => 0,
		self::CREATED => 0,
		self::UPDATED => 0,
		self::NO_OP => 0,
		self::SKIPPED => 0,
		self::ERRORS => 0,
		self::CAPPED => 0,
		self::FLAG_OFF => 0,
		self::INVALID_STORAGE => 0,
		self::FOREIGN_STORAGE => 0,
	];

	public static function normalizeLimit(mixed $value): int
	{
		if (is_string($value))
		{
			$value = trim($value);
		}

		if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/D', $value)))
		{
			return self::DEFAULT_LIMIT;
		}

		return min(self::MAX_LIMIT, max(0, (int)$value));
	}

	public function addAttempt(int $count = 1): self
	{
		return $this->increment(self::ATTEMPTS, $count);
	}

	public function addCreated(int $count = 1): self
	{
		return $this->increment(self::CREATED, $count);
	}

	public function addUpdated(int $count = 1): self
	{
		return $this->increment(self::UPDATED, $count);
	}

	public function addNoOp(int $count = 1): self
	{
		return $this->increment(self::NO_OP, $count);
	}

	public function addSkipped(int $count = 1): self
	{
		return $this->increment(self::SKIPPED, $count);
	}

	public function addError(int $count = 1): self
	{
		return $this->increment(self::ERRORS, $count);
	}

	public function addCapped(int $count = 1): self
	{
		return $this->increment(self::CAPPED, $count);
	}

	public function addFlagOff(int $count = 1): self
	{
		return $this->increment(self::FLAG_OFF, $count);
	}

	public function addInvalidStorage(int $count = 1): self
	{
		return $this->increment(self::INVALID_STORAGE, $count);
	}

	public function addForeignStorage(int $count = 1): self
	{
		return $this->increment(self::FOREIGN_STORAGE, $count);
	}

	public function toArray(): array
	{
		return $this->counters;
	}

	private function increment(string $counter, int $count): self
	{
		$this->counters[$counter] += $count;

		return $this;
	}
}
