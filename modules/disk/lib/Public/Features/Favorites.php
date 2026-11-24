<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * Personal favorite files and folders of Disk: the star of a row and the "Favorites" filter of the list.
 */
final class Favorites extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}
}
