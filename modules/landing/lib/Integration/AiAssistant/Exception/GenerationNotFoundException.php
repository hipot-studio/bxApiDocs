<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Exception;

/**
 * There is no site generation job the caller may work with: the identifier is not a valid one,
 * the record is missing or it belongs to another scenario.
 */
final class GenerationNotFoundException extends \RuntimeException
{
	/**
	 * The single wording every refusal of this path carries, foreign and off-scenario jobs included.
	 *
	 * Generation ids are an autoincrement key, so a distinguishable answer would let a caller probe
	 * which ids exist and which scenario they run.
	 */
	public const MESSAGE = 'Site generation job was not found.';

	public function __construct(
		string $message = self::MESSAGE,
		?\Throwable $previous = null,
	)
	{
		parent::__construct($message, 0, $previous);
	}
}
