<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Link\File;

use Bitrix\Disk\BaseObject;
use Bitrix\Im\V2\Logger;
use Bitrix\Main\Event;
use Throwable;

class DiskObjectRenameHandler
{
	public static function onAfterRenameObject(Event $event): void
	{
		try
		{
			$object = $event->getParameter(0);
			if (!$object instanceof BaseObject)
			{
				return;
			}

			static::createIndexer()->updateAfterRename($object);
		}
		catch (Throwable $throwable)
		{
			try
			{
				static::writeDiagnostic($throwable);
			}
			catch (Throwable)
			{
			}
		}
	}

	protected static function createIndexer(): PublishedFileHeadIndexer
	{
		return new PublishedFileHeadIndexer();
	}

	protected static function writeDiagnostic(Throwable $throwable): void
	{
		(new Logger('disk-im-head-indexing-rename-handler'))->logThrowable($throwable);
	}
}
