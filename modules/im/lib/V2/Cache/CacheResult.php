<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Cache;

/**
 * @template T of CacheableEntity
 */
class CacheResult
{
	/**
	 * @param CacheStatus $status
	 * @param T|null $object
	 */
	private function __construct(
		public readonly CacheStatus $status,
		public readonly ?CacheableEntity $object = null
	) {}

	/**
	 * @param T $object
	 * @return self<T>
	 */
	public static function hit(CacheableEntity $object): self
	{
		return new self(CacheStatus::Hit, $object);
	}

	/**
	 * @return self<T>
	 */
	public static function miss(): self
	{
		return new self(CacheStatus::Miss);
	}

	public static function negativeHit(NullEntity $object): self
	{
		return new self(CacheStatus::NegativeHit, $object);
	}

	public static function hitOrNegativeHit(CacheableEntity $object): self
	{
		if ($object instanceof NullEntity)
		{
			return self::negativeHit($object);
		}

		return self::hit($object);
	}

	public function isMiss(): bool
	{
		return $this->status === CacheStatus::Miss;
	}

	public function isHit(): bool
	{
		return $this->status === CacheStatus::Hit;
	}

	public function isNegativeHit(): bool
	{
		return $this->status === CacheStatus::NegativeHit;
	}

	/**
	 * @return T|null
	 */
	public function getResult(): ?CacheableEntity
	{
		return match ($this->status)
		{
			CacheStatus::Hit => $this->object,
			CacheStatus::NegativeHit, CacheStatus::Miss => null,
		};
	}
}
