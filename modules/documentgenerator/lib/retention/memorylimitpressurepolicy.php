<?php

namespace Bitrix\DocumentGenerator\Retention;

/**
 * @internal
 */
class MemoryLimitPressurePolicy extends PressurePolicy
{
	private ?int $memoryLimitBytes = null;
	private ?int $nextPressureTrigger = null;
	private float $threshold;
	private float $batchShare;
	private float $step;

	/**
	 * @param float $threshold
	 * @param float $batchShare
	 * @param float $step
	 */
	public function __construct(float $threshold = 0.7, float $batchShare = 0.5, float $step = 0.05)
	{
		$this->threshold = $threshold;
		$this->batchShare = $batchShare;
		$this->step = $step;
	}

	/**
	 * Parses a PHP memory limit value into bytes.
	 *
	 * @param string $value
	 * @return int
	 */
	public static function parseMemoryLimit(string $value): int
	{
		$value = trim($value);
		if ($value === '' || $value === '-1')
		{
			return -1;
		}

		if (preg_match('/^([0-9]+)([KMG]?)$/i', $value, $matches) !== 1)
		{
			return -1;
		}

		$multiplier = 1;
		switch (strtoupper($matches[2] ?? ''))
		{
			case 'K':
				$multiplier = 1024;
				break;
			case 'M':
				$multiplier = 1024 * 1024;
				break;
			case 'G':
				$multiplier = 1024 * 1024 * 1024;
				break;
		}

		$limit = (int)$matches[1];
		if ($limit > intdiv(PHP_INT_MAX, $multiplier))
		{
			return PHP_INT_MAX;
		}

		return $limit * $multiplier;
	}

	/**
	 * @inheritDoc
	 */
	public function isUnderPressure(): bool
	{
		$memoryLimit = $this->getMemoryLimitBytes();
		if ($memoryLimit < 0)
		{
			return false;
		}

		$usage = $this->readUsage();

		$threshold = (int)($memoryLimit * $this->threshold);
		if ($usage < $threshold)
		{
			$this->nextPressureTrigger = $threshold;
		}

		return $usage >= $this->nextPressureTrigger;
	}

	/**
	 * @inheritDoc
	 */
	public function getBatchShare(): float
	{
		return $this->batchShare;
	}

	/**
	 * @inheritDoc
	 */
	public function afterBatch(int $evicted): void
	{
		if ($evicted <= 0)
		{
			return;
		}

		$this->collectCycles();

		$memoryLimit = $this->getMemoryLimitBytes();
		if ($memoryLimit < 0)
		{
			return;
		}

		$usage = $this->readUsage();
		$step = (int)($memoryLimit * $this->step);
		$threshold = (int)($memoryLimit * $this->threshold);
		$this->nextPressureTrigger = max(
			$threshold,
			$usage > PHP_INT_MAX - $step ? PHP_INT_MAX : $usage + $step,
		);
	}

	/**
	 * @inheritDoc
	 */
	public function reset(): void
	{
		$this->memoryLimitBytes = null;
		$this->nextPressureTrigger = null;
	}

	protected function readUsage(): int
	{
		return memory_get_usage();
	}

	protected function readMemoryLimit(): string
	{
		return (string)ini_get('memory_limit');
	}

	protected function collectCycles(): int
	{
		return gc_collect_cycles();
	}

	private function getMemoryLimitBytes(): int
	{
		if ($this->memoryLimitBytes === null)
		{
			$this->memoryLimitBytes = self::parseMemoryLimit($this->readMemoryLimit());
		}

		if ($this->memoryLimitBytes >= 0 && $this->nextPressureTrigger === null)
		{
			$this->nextPressureTrigger = (int)($this->memoryLimitBytes * $this->threshold);
		}

		return $this->memoryLimitBytes;
	}
}
