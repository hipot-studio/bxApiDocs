<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Tracking;

use Bitrix\Crm\V2\Public\Entity\Item\Utm;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Type\DateTime;

/**
 * A visit that brought an item: its source, pages, channels and UTM tags.
 *
 * Source 0 is stored by legacy for a trace without a source.
 *
 * @internal
 */
final readonly class Trace
{
	public const NO_SOURCE = 0;

	/** @var list<TracePage> */
	public array $pages;

	/** @var list<TraceChannel> */
	public array $channels;

	/**
	 * @param TracePage[] $pages
	 * @param TraceChannel[] $channels
	 * @throws ArgumentException
	 */
	public function __construct(
		public ?int $id,
		public int $sourceId,
		public DateTime $createdTime,
		public bool $isMobile,
		array $pages = [],
		array $channels = [],
		public ?Utm $utm = null,
	)
	{
		if ($sourceId < self::NO_SOURCE)
		{
			throw new ArgumentOutOfRangeException('sourceId', self::NO_SOURCE);
		}

		$this->pages = self::listOf(TracePage::class, $pages, 'pages');
		$this->channels = self::listOf(TraceChannel::class, $channels, 'channels');
	}

	public function hasSource(): bool
	{
		return $this->sourceId !== self::NO_SOURCE;
	}

	public function getSourceIdOrNull(): ?int
	{
		return $this->hasSource() ? $this->sourceId : null;
	}

	/**
	 * Returns a copy of the trace with another source; 0 means no source.
	 *
	 * @throws ArgumentException
	 */
	public function withSource(int $sourceId): self
	{
		return $this->copy($sourceId, clone $this->createdTime);
	}

	/**
	 * Returns a copy of the trace created at another moment.
	 *
	 * @throws ArgumentException
	 */
	public function withCreatedTime(DateTime $createdTime): self
	{
		return $this->copy($this->sourceId, clone $createdTime);
	}

	private function copy(int $sourceId, DateTime $createdTime): self
	{
		return new self(
			$this->id,
			$sourceId,
			$createdTime,
			$this->isMobile,
			$this->pages,
			$this->channels,
			$this->utm === null ? null : clone $this->utm,
		);
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @return list<T>
	 * @throws ArgumentException
	 */
	private static function listOf(string $class, array $values, string $parameter): array
	{
		foreach ($values as $value)
		{
			if (!$value instanceof $class)
			{
				throw new ArgumentException("Every element of {$parameter} must be {$class}", $parameter);
			}
		}

		return array_values($values);
	}
}
