<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Avatar;

use Bitrix\UI\FileUploader\Configuration;
use Bitrix\UI\FileUploader\UploadRequest;

final class AvatarUploadContract
{
	public function getConfiguration(): Configuration
	{
		return new Configuration([
			'maxFileSize' => AvatarImageProcessor::MAX_FILE_SIZE,
			'imageMaxFileSize' => AvatarImageProcessor::MAX_FILE_SIZE,
			'imageMaxWidth' => AvatarImageProcessor::MAX_DIMENSION,
			'imageMaxHeight' => AvatarImageProcessor::MAX_DIMENSION,
			'acceptedFileTypes' => ['.jpg', '.jpeg', '.png'],
			'acceptOnlyImages' => true,
			'ignoreUnknownImageTypes' => false,
			'treatOversizeImageAsFile' => false,
		]);
	}

	public function validate(?UploadRequest $request): ?string
	{
		if ($request === null)
		{
			return AvatarImageProcessor::ERROR_UPLOAD_INVALID;
		}

		return AvatarImageProcessor::findDimensionError($request->getWidth(), $request->getHeight());
	}
}
