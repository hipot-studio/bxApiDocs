<?php

namespace Bitrix\Mobile\Profile\Tab;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Folder;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Mobile\Profile\Enum\TabContextType;
use Bitrix\Mobile\Profile\Enum\TabType;

class FilesTab extends BaseProfileTab
{
	private Folder $folder;

	/**
	 * @return TabType
	 */
	public function getType(): TabType
	{
		return TabType::FILES;
	}

	/**
	 * @return TabContextType
	 */
	public function getContextType(): TabContextType
	{
		return TabContextType::WIDGET;
	}

	/**
	 * @return bool
	 */
	public function isAvailable(): bool
	{
		if (!Loader::includeModule('disk') || !Loader::includeModule('diskmobile'))
		{
			return false;
		}

		return $this->hasRootFolder();
	}

	/**
	 * @return string
	 */
	public function getTitle(): string
	{
		return Loc::getMessage('PROFILE_TAB_FILES_TITLE');
	}

	public function getParams(): array
	{
		return [
			'folderId' => $this->folder->getId(),
			'context' => [
				'storageId' => $this->folder->getStorageId(),
			],
		];
	}

	/**
	 * The root folder of the owner's storage, taken straight from the driver. Reading it is not what the
	 * tab stands on: a viewer reached by a shared folder inside a storage they may not read at the root
	 * still gets the tab (mantis 0221728), and the list behind it answers only what they are allowed.
	 */
	private function hasRootFolder(): bool
	{
		$folder = Driver::getInstance()->getStorageByUserId($this->ownerId)?->getRootObject();
		if (!$folder instanceof Folder)
		{
			return false;
		}

		$this->folder = $folder;

		return true;
	}
}
