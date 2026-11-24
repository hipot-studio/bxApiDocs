<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\MarkdownEditor;

/**
 * One budget a call of the Markdown preview spends: calls and bytes a subject may preview in the window
 * of {@see PreviewRateLimiter}.
 */
final class PreviewBudget
{
	public function __construct(
		public readonly string $subject,
		public readonly int $callLimit,
		public readonly int $byteLimit,
	)
	{
	}
}
