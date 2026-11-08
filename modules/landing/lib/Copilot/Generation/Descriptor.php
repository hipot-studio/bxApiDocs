<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation;

/**
 * Identity of a generation without its payload.
 *
 * Answers "does this job exist, whose is it, which scenario is it and is it already over"
 * from the light columns alone, so a caller can authorize a job without reading SITE_DATA, DATA
 * and HTML_BLOCKS. When the payload itself is needed, load it afterwards: Generation::initById(),
 * or Generation::initByIdWithoutHtmlBlocks() on a path that never asks for the html blocks.
 */
readonly class Descriptor
{
	public function __construct(
		public int $id,
		public string $scenarioClass,
		public int $authorId,
		public bool $isFinished,
		public bool $hasError,
	)
	{
	}

	/**
	 * True when the generation cannot change any more, so a long-poll has nothing left to wait for.
	 *
	 * A job ends either by finishing or by failing, and the two marks are persisted apart:
	 * the finish date on the generation, the error on the step that raised it.
	 *
	 * @return bool
	 */
	public function isTerminal(): bool
	{
		return $this->isFinished || $this->hasError;
	}

	/**
	 * Answers like `$generation->getScenario() instanceof $scenarioClass`, but on the class name.
	 *
	 * @param string $scenarioClass Expected scenario class.
	 *
	 * @return bool
	 */
	public function isScenario(string $scenarioClass): bool
	{
		return is_a($this->scenarioClass, $scenarioClass, allow_string: true);
	}

	/**
	 * @param int $userId Effective caller.
	 *
	 * @return bool True when the generation belongs to the caller.
	 */
	public function isOwnedBy(int $userId): bool
	{
		return $this->authorId > 0 && $this->authorId === $userId;
	}
}
