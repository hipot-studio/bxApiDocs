<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\Tracking;

use Bitrix\Main\IO\Path;
use Bitrix\Main\Localization\LocalizableMessage;
use RuntimeException;

/**
 * A value of a source or of an expenses pack is refused before anything is written.
 *
 * The phrase of the refusal belongs to the language file of the class that created the exception, so each
 * service keeps the texts of its own checks.
 *
 * @internal
 */
final class TrackingValidationException extends RuntimeException
{
	/**
	 * @param string $field the refused field, named as the callers of the service name it
	 * @param string $phraseCode the phrase in the language file of the class creating the exception
	 * @param array<string, string|int|float> $replace
	 */
	public function __construct(
		public readonly string $field,
		public readonly string $phraseCode,
		public readonly array $replace = [],
	)
	{
		parent::__construct(sprintf('Tracking field `%s` is invalid: %s.', $field, $phraseCode));
	}

	public function getLocalizableMessage(): LocalizableMessage
	{
		return new LocalizableMessage($this->phraseCode, $this->replace, null, Path::normalize($this->getFile()));
	}
}
