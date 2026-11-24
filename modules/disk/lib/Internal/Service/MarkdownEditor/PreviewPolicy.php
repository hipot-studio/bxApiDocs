<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\MarkdownEditor;

use Bitrix\Disk\Public\Features\MarkdownSplitPreview;
use Bitrix\Main\Config\Feature;

/**
 * The single answer to "is the preview of the text being edited offered", asked by the preview action and by
 * what builds the edit options of the page, so the page never offers a preview the server refuses.
 */
final class PreviewPolicy
{
	public static function isEnabled(): bool
	{
		return Feature::isEnabled(MarkdownSplitPreview::class);
	}
}
