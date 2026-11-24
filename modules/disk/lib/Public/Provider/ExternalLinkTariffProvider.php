<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Provider;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Integration\Bitrix24Manager;
use Bitrix\Disk\TypeFile;

class ExternalLinkTariffProvider
{
	public const FOLDER_FEATURE = 'disk_manual_external_folder';
	public const BOARD_FEATURE = 'disk_board_external_link';
	public const FILE_FEATURE = 'disk_manual_external_link';

	/**
	 * Tells whether the tariff allows creating a manual public link of the object.
	 *
	 * @param BaseObject $object Object or its shortcut.
	 * @return bool
	 */
	public function isManualLinkCreationAllowed(BaseObject $object): bool
	{
		$realObject = $object->getRealObject();
		if (!$realObject)
		{
			return false;
		}

		return $this->isFeatureEnabled($this->getFeatureCodeOfRealObject($realObject));
	}

	/**
	 * Returns the tariff feature that allows creating a manual public link of the object.
	 *
	 * @param BaseObject $object Object or its shortcut.
	 * @return string
	 */
	public function getFeatureCode(BaseObject $object): string
	{
		return $this->getFeatureCodeOfRealObject($object->getRealObject() ?? $object);
	}

	protected function isFeatureEnabled(string $featureCode): bool
	{
		return (bool)Bitrix24Manager::isFeatureEnabled($featureCode);
	}

	private function getFeatureCodeOfRealObject(BaseObject $realObject): string
	{
		if ($realObject instanceof Folder)
		{
			return self::FOLDER_FEATURE;
		}

		if ($realObject instanceof File && (int)$realObject->getTypeFile() === TypeFile::BOARD)
		{
			return self::BOARD_FEATURE;
		}

		return self::FILE_FEATURE;
	}
}
