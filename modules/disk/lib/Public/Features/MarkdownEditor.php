<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * The source of a Markdown file is edited on the page of its tab. The release of the feature is
 * {@see MarkdownTab}: editing comes with the tab, and this flag only switches editing off on a portal that
 * keeps the tab read-only. Without the tab there is no page to edit on, so the flag means nothing alone.
 */
final class MarkdownEditor extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return true;
	}
}
