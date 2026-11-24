<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\MarkdownEditor;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\MarkdownTabPolicy;
use Bitrix\Disk\Public\Features\MarkdownEditor;
use Bitrix\Disk\Version;
use Bitrix\Main\Config\Feature;

/**
 * The single answer to "may the tab edit this Markdown source", asked by what builds the page, by every
 * action of editing and by the branch of a public link: keeping the whole condition here is what keeps the
 * "Edit" button and the server answering it in agreement.
 *
 * Only the head of the file itself is edited: a revision is shown as it was saved, and an attachment
 * opens the file in an entity of somebody else. Rights are not part of the answer - they are the question
 * of the caller.
 */
final class EditPolicy
{
	public static function isEnabled(): bool
	{
		return Feature::isEnabled(MarkdownEditor::class);
	}

	public static function isEditableSource(
		File $file,
		?Version $version = null,
		?AttachedObject $attached = null,
	): bool
	{
		return $version === null
			&& $attached === null
			&& MarkdownTabPolicy::isTabFile($file)
			&& self::isEnabled()
		;
	}
}
