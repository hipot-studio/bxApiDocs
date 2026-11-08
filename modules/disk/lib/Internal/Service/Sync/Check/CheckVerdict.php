<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Check;

/**
 * What a state check says about one object: it was asked about, and the reader either sees it right
 * now or does not.
 *
 * The verdict carries no card and no reason. The card is assembled for the whole page at once, out
 * of the objects the verdicts declared visible, by the method that answers the request - assembling
 * one per verdict would be a query per object. A reason is the one thing a check must never publish:
 * an object hidden from the reader and an object that was never there are answered by these same two
 * fields and by nothing else, so the answer cannot be read as an oracle of existence.
 *
 * One shape serves both modes of the check. The mode over a list is told which objects to answer
 * about; the mode over an area finds them itself and declares every object it found visible. What
 * differs between the modes is how the objects are gathered, never what is said about one of them.
 */
final class CheckVerdict
{
	private function __construct(
		private readonly int $objectId,
		private readonly bool $visible,
	)
	{
	}

	public static function visible(int $objectId): self
	{
		return new self($objectId, true);
	}

	/**
	 * A pure function of the identifier the client itself sent: two invisible verdicts of the same
	 * object are identical whatever made them invisible, and nothing learnt about the object on the
	 * way reaches the answer.
	 */
	public static function invisible(int $objectId): self
	{
		return new self($objectId, false);
	}

	public function getObjectId(): int
	{
		return $this->objectId;
	}

	public function isVisible(): bool
	{
		return $this->visible;
	}
}
