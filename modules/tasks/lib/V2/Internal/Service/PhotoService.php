<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service;

use Bitrix\Tasks\V2\Internal\Entity;
use CFile;

class PhotoService
{
	public function resize(Entity\File $photo, int $width = 100, int $height = 100): ?Entity\File
	{
		$resizedPhoto = CFile::resizeImageGet(
			$photo->file,
			['width' => $width, 'height' => $height],
			BX_RESIZE_IMAGE_EXACT,
			false,
			false,
			true
		);

		if (!isset($resizedPhoto['src']))
		{
			return null;
		}

		return Entity\File::mapFromArray($resizedPhoto);
	}
}
