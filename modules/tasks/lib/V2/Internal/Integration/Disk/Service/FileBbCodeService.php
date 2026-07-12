<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Disk\Service;

use Bitrix\Disk\Uf\FileUserType;

class FileBbCodeService
{
	private const BB_CODE_PATTERN = '/\[disk file id=(' . FileUserType::NEW_FILE_PREFIX . '?\d+)([^]]*)]/i';

	public function __construct(
		private readonly FileIdService $fileIdService,
	)
	{
	}

	public function containsBbCode(string $text): bool
	{
		return stripos($text, '[disk file id=') !== false;
	}

	public function replaceIds(string $text, array $oldToNewIdMap): string
	{
		if (empty($oldToNewIdMap) || !$this->containsBbCode($text))
		{
			return $text;
		}

		$updatedText = preg_replace_callback(
			self::BB_CODE_PATTERN,
			static function (array $matches) use ($oldToNewIdMap): string
			{
				$oldId = $matches[1];
				$additionalProperties = $matches[2];

				if (!isset($oldToNewIdMap[$oldId]))
				{
					return $matches[0];
				}

				$newId = $oldToNewIdMap[$oldId];

				return "[disk file id={$newId}{$additionalProperties}]";
			},
			$text,
		);

		return $updatedText ?? $text;
	}

	public function stripDetachedBbCodes(string $text, array $detachedFileIds): string
	{
		if (empty($detachedFileIds) || !$this->containsBbCode($text))
		{
			return $text;
		}

		$idToRemoveMap = array_flip($this->fileIdService->expandWithDiskIds($detachedFileIds));

		$updatedText = preg_replace_callback(
			self::BB_CODE_PATTERN,
			static function (array $matches) use ($idToRemoveMap): string
			{
				$originalBbCode = $matches[0];
				$fileId = $matches[1];

				return isset($idToRemoveMap[$fileId]) ? '' : $originalBbCode;
			},
			$text,
		);

		return $updatedText ?? $text;
	}
}
