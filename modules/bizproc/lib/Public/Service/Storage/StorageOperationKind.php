<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\Storage;

/**
 * Kind of a storage-consumer operation the reverse validator classifies per node before calling the guard.
 * A result data-view may be read but not edited, so every non-read kind counts as editing (ALG-02 rule 2);
 * Write (a new item) and Update (rewrite of an existing one) both edit and differ in name only.
 */
enum StorageOperationKind
{
	case Read;
	case Write;
	case Delete;
	case Update;

	public function isEditing(): bool
	{
		return $this !== self::Read;
	}
}
