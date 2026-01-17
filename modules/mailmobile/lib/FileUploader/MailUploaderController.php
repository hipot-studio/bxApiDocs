<?php

namespace Bitrix\MailMobile\FileUploader;

use Bitrix\Mail\Helper\Message;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\UI\FileUploader\FileOwnershipCollection;
use Bitrix\UI\FileUploader\UploaderController;
use Bitrix\UI\FileUploader\Configuration;

final class MailUploaderController extends UploaderController
{
	public function __construct()
	{
		parent::__construct([
			'type' => 'mailUploadedFiles',
			'userId' => (int)CurrentUser::get()?->getId(),
		]);
	}

	public function isAvailable(): bool
	{
		if (!Loader::includeModule('mail'))
		{
			return false;
		}

		return true;
	}

	/**
	 * @throws LoaderException
	 */
	public function getConfiguration(): Configuration
	{
		$configuration = new Configuration();

		if (Loader::includeModule('mail'))
		{
			$maxSize = Message::getMaxAttachedFilesSizeAfterEncoding();
			if ($maxSize > 0)
			{
				$configuration->setMaxFileSize($maxSize);
			}
		}

		return $configuration;
	}

	public function canUpload(): bool
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