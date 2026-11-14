<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItem;

/**
 * Production implementation of the post-commit seam: does nothing.
 *
 * @see PostCommitHookInterface
 */
final class NoopPostCommitHook implements PostCommitHookInterface
{
	public function beforeAtomicUpdate(DockedUploadItem $item): void
	{
		// Intentionally empty: no crash injection in production.
	}
}
