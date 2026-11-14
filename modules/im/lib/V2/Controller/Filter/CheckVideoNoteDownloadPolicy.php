<?php

namespace Bitrix\Im\V2\Controller\Filter;

use Bitrix\Im\V2\Entity\File\FileCollection;
use Bitrix\Im\V2\Entity\File\FileError;
use Bitrix\Im\V2\Entity\File\FileItem;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/**
 * Stands after CheckDiskFileAccess: the read permission on the file is checked as before and the policy
 * does not replace it. A batch holding a video note is refused whole, so no partial export can happen.
 */
class CheckVideoNoteDownloadPolicy extends Base
{
	public function onBeforeAction(Event $event)
	{
		foreach ($this->getAction()->getArguments() as $argument)
		{
			if ($argument instanceof FileCollection)
			{
				return $this->checkDownloadPolicy($argument);
			}

			if ($argument instanceof FileItem)
			{
				$collection = new FileCollection();
				$collection->offsetSet($argument->getId(), $argument);

				return $this->checkDownloadPolicy($collection);
			}
		}

		return null;
	}

	private function checkDownloadPolicy(FileCollection $files): ?EventResult
	{
		// one query warms the flags of the whole batch, links included: they are read right below
		$files->loadParams();

		if (!$this->hasVideoNote($files))
		{
			return null;
		}

		if (Locator::getVideoNoteDownloadPolicy()->isAllowed(User::getCurrent()))
		{
			return null;
		}

		$this->addError(new FileError(FileError::VIDEO_NOTE_DOWNLOAD_RESTRICTED));

		return new EventResult(EventResult::ERROR, null, null, $this);
	}

	private function hasVideoNote(FileCollection $files): bool
	{
		foreach ($files as $file)
		{
			if ($file->isActualVideoNote())
			{
				return true;
			}
		}

		return false;
	}
}
