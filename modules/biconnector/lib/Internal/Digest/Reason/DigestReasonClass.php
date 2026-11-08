<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Reason;

/**
 * Case values are compared by workflow template expressions, so renaming one breaks the template silently.
 */
enum DigestReasonClass: string
{
	case Settings = 'settings';
	case Temporary = 'temporary';
	case Configuration = 'configuration';
	/**
	 * Overlap is a run outcome rather than a refusal class, but the template reads it through the same property.
	 */
	case Overlap = 'overlap';

	public function code(): string
	{
		return $this->value;
	}
}
