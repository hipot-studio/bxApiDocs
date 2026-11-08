<?php

namespace Bitrix\StaffTrack\Internal\Integration\Disk\Office;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\StaffTrack\Access\Model\OfficeModel;
use Bitrix\StaffTrack\Access\OfficeAccessController;
use Bitrix\StaffTrack\Access\OfficeAction;
use Bitrix\StaffTrack\Internal\Service\Office\OfficesAvailability;
use Bitrix\UI\FileUploader\Configuration;
use Bitrix\UI\FileUploader\FileOwnershipCollection;
use Bitrix\UI\FileUploader\UploaderController;

/**
 * The entrance for the files of an office card, not for the card itself: a client uploads images here,
 * gets tokens back and hands those tokens to the actions of the directory.
 *
 * This is a separate entrance, so the barriers of the ajax controller do not cover it and are repeated
 * here: the same availability of the directory the prefilter of the controller asks about, and the
 * same right the creation of an office asks about - at the moment of the upload the office may not
 * exist yet.
 */
class UploadController extends UploaderController
{
	private const MAX_FILE_SIZE = 20 * 1024 * 1024;

	public function __construct(array $options = [])
	{
		parent::__construct($options);
	}

	public function isAvailable(): bool
	{
		return $this->getUserId() > 0
			&& $this->isDiskAvailable()
			&& $this->areOfficesAvailable()
		;
	}

	/**
	 * Anything but an image is refused: the storage of the module is not a place for arbitrary files.
	 */
	public function getConfiguration(): Configuration
	{
		return (new Configuration())
			->setMaxFileSize(self::MAX_FILE_SIZE)
			->acceptOnlyImages()
		;
	}

	public function canUpload(): bool
	{
		return $this->isAvailable() && $this->canAddOffice();
	}

	public function verifyFileOwner(FileOwnershipCollection $files): void
	{
	}

	public function canView(): bool
	{
		return false;
	}

	public function canRemove(): bool
	{
		return false;
	}

	protected function getUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	protected function isDiskAvailable(): bool
	{
		return Loader::includeModule('disk');
	}

	protected function areOfficesAvailable(): bool
	{
		return (new OfficesAvailability())->isAvailable();
	}

	protected function canAddOffice(): bool
	{
		return OfficeAccessController::getInstance($this->getUserId())
			->check(OfficeAction::ADD, OfficeModel::createFromId(0))
		;
	}
}
