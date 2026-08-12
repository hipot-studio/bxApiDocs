<?php

namespace Bitrix\Superset\Public\Support;

use Bitrix\Superset\Public\Dto\ArchiveFileDto;

final class ArchiveFileNormalizer
{
	public function normalize(ArchiveFileDto $uploadedFile): array
	{
		return $uploadedFile->toArray();
	}
}
