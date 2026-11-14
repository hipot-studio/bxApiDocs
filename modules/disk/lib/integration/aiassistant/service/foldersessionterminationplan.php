<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Service;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Folder;

final class FolderSessionTerminationPlan
{
	/** @param list<BaseObject> $boards */
	public function __construct(
		public readonly Folder $folder,
		public readonly array $boards,
	)
	{
	}
}
