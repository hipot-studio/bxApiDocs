<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * A Markdown file opens on a page of a tab of its own instead of the slider.
 */
final class MarkdownTab extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}
}
