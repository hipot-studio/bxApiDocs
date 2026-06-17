<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Debugger;

use Bitrix\Bizproc\Internal\Exception\Debugger\DebuggerException;
use DateTimeImmutable;
use DateTimeInterface;

final class Timestamp implements \Stringable
{
	public readonly float $value;

	public function __construct(
		float $value,
	)
	{
		$this->value = $value;
		$this->validate();
	}

	public static function now(): self
	{
		return new self(microtime(true));
	}

	public static function fromFloat(float $value): self
	{
		return new self($value);
	}

	public static function fromDateTime(DateTimeInterface $dateTime): self
	{
		$value = (float)$dateTime->format('U.u');

		return new self($value);
	}

	public static function fromUnixTimestamp(int $timestamp): self
	{
		return new self((float)$timestamp);
	}

	public static function tryFromFloat(float $value): ?self
	{
		try
		{
			return new self($value);
		}
		catch (DebuggerException)
		{
			return null;
		}
	}

	public function getValue(): float
	{
		return $this->value;
	}

	public function toDateTime(): DateTimeImmutable
	{
		return DateTimeImmutable::createFromFormat('U.u', sprintf('%.6f', $this->value))
			?: new DateTimeImmutable('@' . (int)$this->value);
	}

	public function format(string $format = 'Y-m-d H:i:s.u'): string
	{
		return $this->toDateTime()->format($format);
	}

	public function toUnixTimestamp(): int
	{
		return (int)$this->value;
	}

	public function diffInSeconds(self $other): float
	{
		return abs($this->value - $other->value);
	}

	public function diffInMilliseconds(self $other): float
	{
		return $this->diffInSeconds($other) * 1000;
	}

	public function isAfter(self $other): bool
	{
		return $this->value > $other->value;
	}

	public function isBefore(self $other): bool
	{
		return $this->value < $other->value;
	}

	public function equals(self $other): bool
	{
		return abs($this->value - $other->value) < 0.000001;
	}

	public function addSeconds(float $seconds): self
	{
		return new self($this->value + $seconds);
	}

	public function subSeconds(float $seconds): self
	{
		return new self($this->value - $seconds);
	}

	public function isOlderThan(int $days): bool
	{
		$threshold = self::now()->subSeconds($days * 86400);

		return $this->isBefore($threshold);
	}

	public function toDisplayString(): string
	{
		return $this->format('d.m.Y H:i:s');
	}

	public function __toString(): string
	{
		return $this->format();
	}

	/**
	 * @throws DebuggerException
	 */
	private function validate(): void
	{
		if ($this->value <= 0)
		{
			throw DebuggerException::invalidTimestamp('Timestamp must be positive');
		}

		$minTimestamp = 946684800.0;
		$maxTimestamp = 4102444800.0;

		if ($this->value < $minTimestamp || $this->value > $maxTimestamp)
		{
			throw DebuggerException::invalidTimestamp(
				'Timestamp out of valid range (2000-2100)',
			);
		}
	}
}
