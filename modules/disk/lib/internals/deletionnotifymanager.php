<?php

namespace Bitrix\Disk\Internals;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Folder;
use Bitrix\Disk\ProxyType;
use Bitrix\Disk\User;
use Bitrix\Main\Localization\Loc;

final class DeletionNotifyManager
{
	/** @var array */
	private $map = [];
	/** @var array */
	private $notifiedFolderKeys = [];
	private int $muteCounter = 0;

	/**
	 * Silences the manager until the matching unmute(): a deletion that is a step of another operation,
	 * such as a move to another storage, is not reported to the author.
	 */
	public function mute(): void
	{
		$this->muteCounter++;
	}

	public function unmute(): void
	{
		if ($this->muteCounter > 0)
		{
			$this->muteCounter--;
		}
	}

	public function put(BaseObject $object, int $deletedBy): void
	{
		if ($this->muteCounter > 0)
		{
			return;
		}

		//author can delete object without notification
		if ($object->getCreatedBy() && ($object->getCreatedBy() == $deletedBy))
		{
			return;
		}

		$storage = $object->getStorage();
		//we notify only when file belongs to user (not group and not common disk)
		if (!$storage || !($storage->getProxyType() instanceof ProxyType\User))
		{
			return;
		}

		//notification about a folder replaces notifications about its content, whatever order the cascade takes
		if ($object instanceof Folder)
		{
			$folderKey = $object->getId() . "-{$object->getCreatedBy()}";
			$this->notifiedFolderKeys[$folderKey] = true;
			unset($this->map[$folderKey]);
		}

		$key = $object->getParentId() . "-{$object->getCreatedBy()}";
		if (isset($this->notifiedFolderKeys[$key]))
		{
			return;
		}

		$this->map[$key][] = [
			$deletedBy,
			$object->getCreatedBy(),
			$object->getId(),
			$object->getName(),
			$object instanceof Folder,
		];
	}

	public function send()
	{
		$urlManager = Driver::getInstance()->getUrlManager();
		foreach ($this->map as $deletions)
		{
			foreach ($deletions as $deletionData)
			{
				[$deletedBy, $createdBy, $objectId, $name, $isFolder] = $deletionData;

				$deleteUser = User::getById($deletedBy);
				if (!$deleteUser)
				{
					continue;
				}

				$link = $urlManager::getUrlFocusController('showObjectInTrashCanGrid', [
					'objectId' => $objectId,
				]);

				$type = $isFolder? 'FOLDER' : 'FILE';
				$gender = $deleteUser->getPersonalGender() === 'F' ? 'F' : 'M';
				$text = Loc::getMessage("DISK_DELETION_MANAGER_NOTIFY_ABOUT_DELETION_{$type}_{$gender}", [
					'#NAME#' => "<a href=\"{$link}\">{$name}</a>",
				]);

				Driver::getInstance()->sendNotify($createdBy, [
					'FROM_USER_ID' => $deletedBy,
					'NOTIFY_EVENT' => 'deletion',
					'NOTIFY_TAG' => Driver::INTERNAL_MODULE_ID . "|DEL|{$objectId}",
					'NOTIFY_MESSAGE' => $text,
				]);
			}
		}

		$this->map = [];
		$this->notifiedFolderKeys = [];
	}
}