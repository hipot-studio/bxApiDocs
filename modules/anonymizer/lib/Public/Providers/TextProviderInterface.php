<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Public\Providers;

/**
 * Text provider: source text for anonymize_text command.
 */
interface TextProviderInterface extends ProviderInterface
{
	/**
	 * Returns the text to be anonymized (or the anonymized text when used as input to deanonymize).
	 *
	 * @return string The text content.
	 */
	public function getText(): string;
}