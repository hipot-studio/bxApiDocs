<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

use Bitrix\Timeman\V2\Internal\Entity\File;

class PhotoService
{
	public function resize(File $photo, int $width = 100, int $height = 100): ?File
	{
		$resizedPhoto = \CFile::ResizeImageGet(
			$photo->file,
			['width' => $width, 'height' => $height],
			BX_RESIZE_IMAGE_EXACT,
			false,
			false,
			true,
		);

		if (!isset($resizedPhoto['src']))
		{
			return null;
		}

		return File::mapFromArray($resizedPhoto);
	}
}
