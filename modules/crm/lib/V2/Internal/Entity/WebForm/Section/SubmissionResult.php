<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Section;

/**
 * @internal
 */
final readonly class SubmissionResult
{
	public const FIELD_URL = 'url';
	public const FIELD_TEXT = 'text';

	public function __construct(
		public ?string $url = null,
		public ?string $text = null,
	)
	{
	}
}
