<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItem;

/**
 * Design-for-test seam of the exactly-once commit (ALG-01).
 *
 * The hook is invoked strictly "after the domain write, before the atomic
 * queue-row update" (i.e. after the disk object/version is durable, but before
 * the queue row is flipped to committed). In production it is a no-op; tests
 * inject a crashing implementation to reproduce a crash between the two durable
 * units and assert that the retry reconciles idempotently.
 *
 * This is deliberately NOT an "if (DEBUG)" branch: the seam is a real injected
 * dependency so production and test share the exact same code path.
 */
interface PostCommitHookInterface
{
	public function beforeAtomicUpdate(DockedUploadItem $item): void;
}
