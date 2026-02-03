<?php

namespace Bitrix\Im\V2\Controller\Sticker;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\IO\Path;
use Bitrix\Main\Loader;
use Bitrix\UI\FileUploader\FileOwnershipCollection;
use Bitrix\UI\FileUploader\Configuration;
use Bitrix\UI\FileUploader\UploaderController;
use Bitrix\UI\FileUploader\UploadResult;

Loader::requireModule('ui');

class StickerUploader extends UploaderController
{
	private const MAX_FILE_SIZE = 512000;
	private const MAX_IMAGE_WIDTH = 512;
	private const MAX_IMAGE_HEIGHT = 512;

	public function __construct()
	{
		parent::__construct([
			'type' => 'stickerUploadedFiles',
			'userId' => (int)CurrentUser::get()?->getId(),
		]);
	}

	public function isAvailable(): bool
	{
		return Features::isStickersAvailable();
	}

	public function getConfiguration(): Configuration
	{
		return (new Configuration())
			->setAcceptedFileTypes(['.jpg', '.png', '.webp', '.jpeg'])
		;
	}

	public function onUploadStart(UploadResult $uploadResult): void
	{
		$tempFile = $uploadResult->getTempFile();

		$fileName = $tempFile->getFilename();

		if (Path::getExtension($fileName) !== 'webp')
		{
			return;
		}

		$width = $tempFile->getWidth();
		$height = $tempFile->getHeight();
		$size = $tempFile->getSize();

		if (
			$size > self::MAX_FILE_SIZE
			|| $width > self::MAX_IMAGE_WIDTH
			|| $height > self::MAX_IMAGE_HEIGHT
		)
		{
			$uploadResult->addError(new Error('File is too large'));
		}
	}

	public function canUpload()
	{
		return true;
	}

	public function canView(): bool
	{
		return true;
	}

	public function verifyFileOwner(FileOwnershipCollection $files): void
	{

	}

	public function canRemove(): bool
	{
		return false;
	}
}
