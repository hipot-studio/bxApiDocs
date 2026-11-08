<?php

namespace Bitrix\StaffTrack\Internal\Integration\Disk\Office;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Storage;
use Bitrix\Main\Loader;

/**
 * The storage the office photos live in: one for the whole module, created on the first request and
 * not on the installation of it. Creation is idempotent and safe against a race - two simultaneous
 * requests end up with the same storage ({@see Driver::addStorageIfNotExist()}).
 *
 * Internal rights of the disk are switched off, so no rights list is passed along with the creation:
 * setting rights on such a storage is an exception. Who may read and change a file is answered by
 * {@see SecurityContext} instead.
 *
 * There is no folder per office: the link between a file and an office is kept by the photo table.
 */
class StorageProvider
{
	private const STORAGE_NAME = 'STAFFTRACK_OFFICE_PHOTO_STORAGE';
	private const MODULE_ID = 'stafftrack';
	private const ENTITY_ID = 'office_photo';

	private ?Storage $storage = null;

	public function getStorage(): ?Storage
	{
		return $this->storage ??= $this->resolveStorage();
	}

	protected function isDiskAvailable(): bool
	{
		return Loader::includeModule('disk');
	}

	protected function addStorageIfNotExist(): ?Storage
	{
		return Driver::getInstance()->addStorageIfNotExist([
			'NAME' => self::STORAGE_NAME,
			'MODULE_ID' => self::MODULE_ID,
			'ENTITY_TYPE' => ProxyType::class,
			'ENTITY_ID' => self::ENTITY_ID,
			'USE_INTERNAL_RIGHTS' => false,
		]);
	}

	private function resolveStorage(): ?Storage
	{
		if (!$this->isDiskAvailable())
		{
			return null;
		}

		$storage = $this->addStorageIfNotExist();
		if ($storage === null)
		{
			return null;
		}

		// A photo needs no document preview, and the storage would order one for every file added.
		if ($storage->isEnabledTransformation())
		{
			$storage->disableTransformation();
		}

		return $storage;
	}
}
