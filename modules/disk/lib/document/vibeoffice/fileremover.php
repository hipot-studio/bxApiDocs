<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

final class FileRemover implements FileRemoverInterface
{
	/**
	 * A blob is gone for good, while the rows pointing at it come back with a rollback. Inside a transaction
	 * of a caller the removal is refused instead: the registry must never outlive the bytes it names.
	 */
	public function remove(int $fileId): bool
	{
		if (DocumentStateRepository::hasOpenTransaction())
		{
			return false;
		}
		\CFile::Delete($fileId);

		return true;
	}
}
