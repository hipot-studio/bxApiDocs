<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository\Mapper;

use Bitrix\Socialnetwork\V2\Internal\Entity\File;
use Bitrix\Socialnetwork\V2\Internal\Entity\FileCollection;

class FileMapper
{
	public function mapToCollection(array $files): FileCollection
	{
		$entities = [];
		foreach ($files as $file)
		{
			$entities[] = $this->mapToEntity($file);
		}

		return new FileCollection(...$entities);
	}

	public function mapToEntity(array $file): File
	{
		return new File(
			id: isset($file['ID']) ? (int)$file['ID'] : null,
			src: $file['SRC'] ?? null,
			name: $file['FILE_NAME'] ?? null,
			width: $file['WIDTH'] ? (int)$file['WIDTH'] : null,
			height: $file['HEIGHT'] ? (int)$file['HEIGHT'] : null,
			subDir: $file['SUBDIR'] ?? null,
			contentType: $file['CONTENT_TYPE'] ?? null,
			file: $file,
		);
	}
}
