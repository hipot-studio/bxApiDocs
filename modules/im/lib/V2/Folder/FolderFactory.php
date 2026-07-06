<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder;

use Bitrix\Im\V2\Folder\System\SystemFolder;
use Bitrix\Im\V2\Folder\System\SystemFolderRegistry;

class FolderFactory
{
	public function __construct(
		private readonly SystemFolderRegistry $systemFolderRegistry,
	) {}

	public function createFromRow(array $row): ?Folder
	{
		$type = (string)($row['TYPE'] ?? PersonalFolder::TYPE);

		if ($type === SystemFolder::TYPE)
		{
			$code = isset($row['CODE']) ? (string)$row['CODE'] : '';
			$prototype = $this->systemFolderRegistry->findByCode($code);
			if ($prototype === null)
			{
				return null;
			}
			$folder = $prototype->instantiate();
		}
		elseif ($type === PersonalFolder::TYPE)
		{
			$folder = new PersonalFolder();
		}
		else
		{
			return null;
		}

		$folder->load($row);

		return $folder;
	}
}
