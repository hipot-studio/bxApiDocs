<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Service\Marketing;

/**
 * The journal of the selection: one line for a definition the registry could not take in and one for a
 * fact a source could not read. The log of the module by default; a caller with a journal of its own,
 * such as the pass of the pushes, hands in its writer.
 */
final class SelectionLog
{
	private const MODULE_ID = 'mobile';
	private const MESSAGE_LIMIT = 300;

	/**
	 * @param \Closure(string): void|null $writer where a line goes; the log of the module when not given
	 */
	public function __construct(private readonly ?\Closure $writer = null)
	{
	}

	public function write(string $message): void
	{
		if ($this->writer !== null)
		{
			($this->writer)($message);

			return;
		}

		\AddMessage2Log($message, self::MODULE_ID);
	}

	/**
	 * The throw as the journal names it: its class and its message, cut.
	 */
	public static function describe(\Throwable $throwable): string
	{
		return $throwable::class . ': ' . self::shorten($throwable->getMessage());
	}

	/**
	 * A message of a throw cut to a bounded length: a module of its own, or SQL naming the ids of a pack, may
	 * make it arbitrarily long.
	 */
	public static function shorten(string $message): string
	{
		if (mb_strlen($message) <= self::MESSAGE_LIMIT)
		{
			return $message;
		}

		return mb_substr($message, 0, self::MESSAGE_LIMIT) . '...';
	}
}
