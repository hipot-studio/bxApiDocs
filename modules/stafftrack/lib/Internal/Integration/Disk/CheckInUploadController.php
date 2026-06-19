<?php

namespace Bitrix\StaffTrack\Internal\Integration\Disk;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Mobile\Config\Feature;
use Bitrix\StaffTrackMobile\Public\Features\CheckInFeature;
use Bitrix\UI\FileUploader\Configuration;
use Bitrix\UI\FileUploader\FileOwnershipCollection;
use Bitrix\UI\FileUploader\UploaderController;

class CheckInUploadController extends UploaderController
{
	public function __construct(array $options = [])
	{
		parent::__construct($options);
	}

	public function isAvailable(): bool
	{
		return (int)CurrentUser::get()->getId() > 0
			&& Loader::includeModule('disk')
			&& Loader::includeModule('mobile')
			&& Feature::isEnabled(CheckInFeature::class);
	}

	public function getConfiguration(): Configuration
	{
		return new Configuration();
	}

	public function canUpload(): bool
	{
		return $this->isAvailable();
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
}
