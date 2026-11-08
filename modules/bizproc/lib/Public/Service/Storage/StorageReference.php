<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\Storage;

/**
 * Neutral projection of a storage-consumer node (read/write/delete) the reverse validator hands to the guard.
 * Kept free of any bizprocdesigner type: the fingerprint is computed by the caller (owner of the agent graph
 * shape) so the guard only compares it against the baseline, while the raw StorageId/StorageCode are the values
 * the agent sent, so the guard can refuse a raw numeric id (anti-IDOR) and resolve the code by itself.
 */
final class StorageReference
{
	public function __construct(
		public readonly ?string $storageCode,
		public readonly mixed $storageId,
		public readonly string $fingerprint,
	) {}
}
