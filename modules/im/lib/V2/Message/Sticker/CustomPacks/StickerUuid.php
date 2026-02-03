<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker\CustomPacks;

use Bitrix\Im\V2\Message\Sticker\StickerCollection;
use Bitrix\Im\V2\Rest\RestConvertible;
use Bitrix\UI\FileUploader\PendingFileCollection;

class StickerUuid implements RestConvertible
{
	protected array $fileMap;
	protected StickerCollection $stickers;

	public function __construct(array $fileMap, StickerCollection $stickers)
	{
		$this->fileMap = $fileMap;
		$this->stickers = $stickers;
	}

	public static function getRestEntityName(): string
	{
		return 'uuids';
	}

	public function toRestFormat(array $option = []): ?array
	{
		$result = [];

		foreach ($this->stickers as $sticker)
		{
			$data = $sticker->toShortRestFormat();
			$data['uuid'] = $this->fileMap[$sticker->fileId] ?? null;
			$result[] = $data;
		}

		return $result;
	}

	public static function getFileMap(PendingFileCollection $pendingFiles): array
	{
		$result = [];
		foreach ($pendingFiles as $file)
		{
			if ($file->getFileId() !== null)
			{
				$result[$file->getFileId()] = $file->getGuid();
			}
		}

		return $result;
	}
}
