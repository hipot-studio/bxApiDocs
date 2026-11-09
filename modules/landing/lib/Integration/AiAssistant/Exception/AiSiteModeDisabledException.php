<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Exception;

/**
 * A new operation of the AI sites generation that is not selected on the portal.
 *
 * The message is constant and starts with the marker: tools carry only the text to the client, and
 * it names neither the mode nor the target, so a refusal reveals nothing about foreign data.
 */
final class AiSiteModeDisabledException extends \RuntimeException
{
	public const ERROR_CODE = 'AI_SITES_MODE_DISABLED';
	public const MESSAGE = 'This AI site generation is not selected on the portal.';

	public function __construct(?\Throwable $previous = null)
	{
		parent::__construct(self::ERROR_CODE . ': ' . self::MESSAGE, 0, $previous);
	}
}
