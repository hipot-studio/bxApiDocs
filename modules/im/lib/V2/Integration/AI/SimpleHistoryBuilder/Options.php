<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\SimpleHistoryBuilder;

/**
 * Immutable selection parameters of the history builder.
 *
 * Every default but the boundary user reproduces the behaviour of SimpleHistoryBuilder::build():
 * backward-only selection, nothing filtered out, reply targets and neighbour transcriptions loaded.
 * The boundary user has no default on purpose: it decides how far back the selection may reach, and
 * a default would be a permissive one - whoever asks for a window has to name whose boundary it is.
 */
final readonly class Options
{
	/**
	 * @param int $boundaryUserId Whose history boundary bounds the selection. Required: this is an
	 *        access boundary, so it is never guessed.
	 * @param bool $withBlocks Whether the neighbours are rendered into blocks. False asks for the
	 *        bounds of the window alone: the selection still runs, but the pool is not prepared,
	 *        the neighbours are not grouped and nothing is rendered, so getBlocks() comes back
	 *        empty. For a caller that only snapshots how far the window reached.
	 * @param int|null $fromId Lower bound of the window snapshot; null - resolved from the chat state.
	 * @param int|null $toId Upper bound of the window snapshot; null - resolved from the chat state.
	 * @param int|null $startId History boundary taken from the snapshot; null - resolved from $boundaryUserId.
	 */
	public function __construct(
		public int $boundaryUserId,
		public Direction $direction = Direction::Backward,
		public bool $withSystemMessages = true,
		public bool $withDeletedMessages = true,
		public bool $withAiTaskCards = true,
		public bool $withReplyTargets = true,
		public bool $withNeighbourTranscriptions = true,
		public bool $withBlocks = true,
		public ?int $fromId = null,
		public ?int $toId = null,
		public ?int $startId = null,
	) {}
}
