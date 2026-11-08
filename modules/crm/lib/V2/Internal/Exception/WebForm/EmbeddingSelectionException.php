<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\WebForm;

use InvalidArgumentException;

/**
 * One embedding value cannot be selected out of what was asked for: an unknown variant, an override
 * belonging to another one, or a value the portal does not publish for the setting it overrides.
 *
 * The refusal carries the field it is about. The caller answering over a transport has to name the
 * key the client sent, and the one thing it must not do is guess that key from the message: every
 * refusal of this kind reads as `InvalidArgumentException` and they are not about one field.
 *
 * @internal
 */
final class EmbeddingSelectionException extends InvalidArgumentException
{
	public function __construct(
		public readonly string $fieldName,
		string $message,
	)
	{
		parent::__construct($message);
	}
}
