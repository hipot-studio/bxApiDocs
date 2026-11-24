<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\MarkdownEditor;

use Bitrix\Main\Error;

/**
 * Whether the viewer may enter editing of the Markdown file now, as {@see ReadinessService} judged it.
 *
 * Without the right there is neither "Edit" nor a reason; with the right and a refusal "Edit" is shown unavailable
 * with the reason - its code, the text and the custom data of the refusal.
 */
final class Readiness
{
	public function __construct(
		public readonly bool $hasRight,
		public readonly bool $canEdit,
		public readonly ?Error $refusal = null,
	)
	{
	}
}
