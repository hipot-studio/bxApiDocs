<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Folder;

use Bitrix\Disk\Folder;

interface DeletionRestrictionRule
{
	public function isRestricted(Folder $folder): bool;

	public function getRestrictedFolderFilter(): array;
}
