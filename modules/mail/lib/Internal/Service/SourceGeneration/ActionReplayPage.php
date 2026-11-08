<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

/**
 * What one page of the catching up pass came back with {@see ActionReplayService::replay()}.
 *
 * Three answers instead of one number. The cursor alone cannot carry them: a page that found
 * nothing left to walk and a page that stopped before it reached a letter both leave the cursor
 * where it was, and the caller has to hold the switch back for one of them and let it through
 * for the other.
 */
final readonly class ActionReplayPage
{
	/** The pass may not work under the context it was given at all */
	public const REFUSAL_CONTEXT = 'context';

	/** The engine of the new source could not be built, so no command of the pass reaches it */
	public const REFUSAL_SOURCE = 'source';

	/**
	 * @param int $cursor The letter the next page continues after: the last one this page is
	 *        really done with, which is not the last one of the page when the pass stopped early.
	 * @param bool $isExhausted The walk reached the end of the mailbox, so the pass is through.
	 * @param string $refusal Why the pass could not work at all, empty when it worked.
	 */
	public function __construct(
		public int $cursor,
		public bool $isExhausted = false,
		public string $refusal = '',
	)
	{
	}

	public function isRefused(): bool
	{
		return $this->refusal !== '';
	}
}
