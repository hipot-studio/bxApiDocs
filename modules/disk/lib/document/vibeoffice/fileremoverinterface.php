<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

interface FileRemoverInterface
{
	/** @return bool false when the blob was left in place and its owner must keep pointing at it. */
	public function remove(int $fileId): bool;
}
