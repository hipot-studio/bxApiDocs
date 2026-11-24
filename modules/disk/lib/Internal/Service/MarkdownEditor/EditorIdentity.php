<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\MarkdownEditor;

use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Security\SecurityContext;

/**
 * Who edits the Markdown file and on what grounds, as {@see AccessResolver} found it: a portal user by a right
 * of their own or by the level "edit" of the unified link (then $uniqueCode is the code that opened it), or a
 * guest of a public link with the right to edit, who has no security context of their own.
 */
final class EditorIdentity
{
	public function __construct(
		public readonly int $userId,
		public readonly File $file,
		public readonly ?SecurityContext $securityContext = null,
		public readonly ?ExternalLink $externalLink = null,
		public readonly ?string $uniqueCode = null,
	)
	{
	}

	public function isGuest(): bool
	{
		return $this->externalLink !== null;
	}
}
