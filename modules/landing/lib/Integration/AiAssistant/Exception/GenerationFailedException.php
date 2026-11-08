<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Exception;

/**
 * An already created generation failed. Carries the generation id so that consumers can report
 * the failure against the very job they started.
 */
final class GenerationFailedException extends \RuntimeException
{
	public function __construct(
		string $message,
		private readonly int $generationId,
		?\Throwable $previous = null,
	)
	{
		parent::__construct($message, 0, $previous);
	}

	public function getGenerationId(): int
	{
		return $this->generationId;
	}
}
