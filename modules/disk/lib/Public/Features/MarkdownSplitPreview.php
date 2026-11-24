<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * The preview of the unsaved text next to the editor of a Markdown file on its tab. It comes with
 * {@see MarkdownEditor}; this flag is the emergency switch that takes the preview away and leaves editing as it
 * was without it.
 */
final class MarkdownSplitPreview extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return true;
	}
}
