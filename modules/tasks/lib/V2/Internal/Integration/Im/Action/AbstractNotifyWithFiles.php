<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Provider\DiskFileProvider;

abstract class AbstractNotifyWithFiles extends AbstractNotify
{
	public function getDiskObjectIds(): array
	{
		$fileIds = $this->getTaskAttachIds();

		if (empty($fileIds) || !Loader::includeModule('disk'))
		{
			return [];
		}

		$diskFileProvider = Container::getInstance()->get(DiskFileProvider::class);
		$files = $diskFileProvider->getObjectsByIds($fileIds);

		$objectIds = [];

		foreach ($files as $file)
		{
			$objectId = $file?->getDiskObjectId();
			if ($objectId !== null)
			{
				$objectIds[] = (int)$objectId;
			}
		}

		return array_values($objectIds);
	}

	protected function getTaskAttachIds(): array
	{
		return [];
	}
}
