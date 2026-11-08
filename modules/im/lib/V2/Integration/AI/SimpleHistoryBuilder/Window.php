<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\SimpleHistoryBuilder;

/**
 * Neighbourhood of the target message rendered as standalone text blocks.
 *
 * The two sides are kept apart: the target message stands between them, so a consumer that reads
 * the window as one flat list cannot tell where it is. The bounds describe the snapshot the window
 * was built within and are meant to be stored and replayed later, when the query is assembled after
 * a deferred transcription.
 */
final readonly class Window
{
	/**
	 * @param string[] $blocksBefore
	 * @param string[] $blocksAfter
	 */
	public function __construct(
		private array $blocksBefore,
		private array $blocksAfter,
		private int $fromId,
		private int $toId,
		private int $startId,
		private bool $isTruncated = false,
	) {}

	/**
	 * @return string[] Both sides in chronological order: what was written before the target
	 *         message, then what was written after it.
	 */
	public function getBlocks(): array
	{
		return [...$this->blocksBefore, ...$this->blocksAfter];
	}

	/**
	 * @return string[] Written before the target message, chronological: the closest to it last.
	 */
	public function getBlocksBefore(): array
	{
		return $this->blocksBefore;
	}

	/**
	 * @return string[] Written after the target message, chronological: the closest to it first.
	 */
	public function getBlocksAfter(): array
	{
		return $this->blocksAfter;
	}

	public function getFromId(): int
	{
		return $this->fromId;
	}

	public function getToId(): int
	{
		return $this->toId;
	}

	public function getStartId(): int
	{
		return $this->startId;
	}

	/**
	 * Whether the window came back shorter than it was asked for - either the history boundary of
	 * the boundary user cut the backward side, or a side ran out of its page budget,
	 * @see \Bitrix\Im\V2\Integration\AI\SimpleHistoryBuilder::MAX_WINDOW_PAGES_PER_SIDE
	 */
	public function isTruncated(): bool
	{
		return $this->isTruncated;
	}
}
