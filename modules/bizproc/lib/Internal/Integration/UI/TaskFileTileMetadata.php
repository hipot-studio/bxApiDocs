<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\UI;

use Bitrix\Bizproc\Controller\File;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\Viewer\ItemAttributes;
use Bitrix\UI\FileUploader\FileInfo;
use Bitrix\UI\FileUploader\PreviewImage;

/**
 * The metadata a tile of an already attached file is drawn by. The card hands it to the uploader instead of
 * letting the uploader load it: a persistent file of a bizproc field is owned by nothing the uploader can
 * verify (TaskUploaderController::verifyFileOwner), so the load is refused and the tile would be an error.
 */
final class TaskFileTileMetadata
{
	/**
	 * @param mixed $value the value of the field, a single one or a list of them
	 * @return array[] one element per value, in order; a value naming no record of a file gives an empty
	 *   element rather than a tile of an error - the value may outlive the file itself
	 */
	public static function forFieldValue(mixed $value): array
	{
		// a field holding nothing has no tiles at all, an empty element being a value of its own
		if ($value === null || $value === '' || $value === [])
		{
			return [];
		}

		$values = is_array($value) ? array_values($value) : [$value];

		return array_map(static fn(mixed $single): array => self::forValue($single), $values);
	}

	private static function forValue(mixed $value): array
	{
		if (!is_numeric($value) || (int)$value <= 0 || !Loader::includeModule('ui'))
		{
			return [];
		}

		return self::forFile((int)$value);
	}

	private static function forFile(int $fileId): array
	{
		$fileInfo = FileInfo::createFromBFile($fileId);

		// a filled name tells the uploader the file is loaded already; without it the tile goes to the
		// server for the metadata it is given here
		if ($fileInfo === null || $fileInfo->getName() === '')
		{
			return [];
		}

		// relative on purpose: an absolute link would carry the host of the `main/server_name` setting
		// instead of the one the page was opened by
		$downloadUrl = File::getPublicLink($fileId, absolute: false);

		$metadata = [
			'serverFileId' => $fileId,
			'name' => $fileInfo->getName(),
			'size' => $fileInfo->getSize(),
			'type' => $fileInfo->getContentType(),
			'downloadUrl' => $downloadUrl,
		];

		if ($fileInfo->isImage())
		{
			$preview = PreviewImage::getSize($fileInfo);

			$metadata['width'] = $fileInfo->getWidth();
			$metadata['height'] = $fileInfo->getHeight();
			// served the way the file itself is: an url of the uploader would tie who sees the image to the
			// checks of the uploader instead
			$metadata['serverPreviewUrl'] = $downloadUrl;
			$metadata['serverPreviewWidth'] = $preview->getWidth();
			$metadata['serverPreviewHeight'] = $preview->getHeight();
		}

		$metadata['viewerAttrs'] = self::viewerAttrs($fileInfo, $downloadUrl);

		return $metadata;
	}

	private static function viewerAttrs(FileInfo $fileInfo, string $downloadUrl): array
	{
		$fileData = [
			'ID' => $fileInfo->getFileId(),
			'CONTENT_TYPE' => $fileInfo->getContentType(),
			'ORIGINAL_NAME' => $fileInfo->getName(),
			'WIDTH' => $fileInfo->getWidth(),
			'HEIGHT' => $fileInfo->getHeight(),
			'FILE_SIZE' => $fileInfo->getSize(),
		];

		return ItemAttributes::buildByFileData($fileData, $downloadUrl)
			->setTitle($fileInfo->getName())
			->toDataSet()
		;
	}
}
