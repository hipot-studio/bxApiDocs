<?php

declare(strict_types=1);

namespace Bitrix\Mail\FileUploader;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Internal\Service\Signature\Image\SignatureImageValidator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\UI\FileUploader\Configuration;
use Bitrix\UI\FileUploader\FileOwnershipCollection;
use Bitrix\UI\FileUploader\UploaderController;

final class SignatureImageUploaderController extends UploaderController
{
	public function __construct()
	{
		parent::__construct([
			'type' => 'mailSignatureImage',
			'userId' => (int)CurrentUser::get()?->getId(),
		]);
	}

	public function isAvailable(): bool
	{
		return Feature::isSignatureImagesAvailable()
			&& (int)CurrentUser::get()?->getId() > 0;
	}

	public function getConfiguration(): Configuration
	{
		return new Configuration([
			'maxFileSize' => SignatureImageValidator::MAX_FILE_SIZE,
			'imageMaxFileSize' => SignatureImageValidator::MAX_FILE_SIZE,
			'acceptedFileTypes' => ['.jpg', '.jpeg', '.png', '.gif'],
		]);
	}

	public function canUpload(): bool
	{
		return $this->isAvailable();
	}

	public function canView(): bool
	{
		return true;
	}

	/**
	 * Signature images own no persistent files of their own: every picture is copied into a new file of
	 * the module, so there is nothing here that a request could claim as already belonging to it.
	 */
	public function verifyFileOwner(FileOwnershipCollection $files): void
	{
	}

	public function canRemove(): bool
	{
		return false;
	}
}
