<?php

declare(strict_types=1);

namespace Bitrix\Disk\FilePicker;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\File;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\UI\Viewer\Renderer\Html;
use Bitrix\Disk\Ui\FileAttributes;
use Bitrix\Main\FileTable;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\UI\Viewer\Renderer;
use Bitrix\Main\UI\Viewer\Transformation\TransformerManager;

/**
 * Tells whether the picker may offer the standard viewer for a file. Linked objects and stored file
 * metadata are loaded in batches, so no listed item costs an individual query.
 */
final class ViewerSupport
{
	/** @var array<int, array{CONTENT_TYPE: string, FILE_SIZE: int, WIDTH: int, HEIGHT: int}|null> */
	private array $fileDataByFileId = [];
	private ?TransformerManager $transformerManager = null;

	public function __construct(
		private readonly RealObjectResolver $realObjectResolver = new RealObjectResolver(),
	)
	{
	}

	/**
	 * Resolves linked objects and reads document and extensionless metadata once for the set.
	 * A link owns no stored file of its own, so both reads use the object behind it.
	 *
	 * @param array<BaseObject> $objects
	 */
	public function warmUpLinkedFiles(array $objects): void
	{
		$this->realObjectResolver->warmUp($objects);
		$this->fileDataByFileId = [];
		foreach ($objects as $object)
		{
			if (
				!$object instanceof File
				|| (!TypeFile::isDocument($object->getName()) && GetFileExtension($object->getName()) !== '')
			)
			{
				continue;
			}

			$fileId = $this->resolveFileId($object);
			if ($fileId > 0)
			{
				$this->fileDataByFileId[$fileId] = null;
			}
		}

		if ($this->fileDataByFileId === [])
		{
			return;
		}

		$rows = FileTable::query()
			->setSelect(['ID', 'CONTENT_TYPE', 'FILE_SIZE', 'WIDTH', 'HEIGHT'])
			->whereIn('ID', array_keys($this->fileDataByFileId))
			->exec()
		;
		while ($row = $rows->fetch())
		{
			$this->fileDataByFileId[(int)$row['ID']] = [
				'CONTENT_TYPE' => (string)$row['CONTENT_TYPE'],
				'FILE_SIZE' => (int)$row['FILE_SIZE'],
				'WIDTH' => (int)$row['WIDTH'],
				'HEIGHT' => (int)$row['HEIGHT'],
			];
		}
	}

	public function isPreviewable(File $file): bool
	{
		$fileId = $this->resolveFileId($file);
		if ($fileId <= 0)
		{
			return false;
		}

		if (TypeFile::isDocument($file->getName()))
		{
			return $this->canPreviewDocument($file, $fileId);
		}

		$storedFileData = null;
		$contentType = TypeFile::getMimeTypeByFilename((string)$file->getName());
		if (GetFileExtension($file->getName()) === '')
		{
			$storedFileData = $this->fileDataByFileId[$fileId] ?? null;
			if ($storedFileData === null)
			{
				return false;
			}

			$contentType = $storedFileData['CONTENT_TYPE'];
			$extension = TypeFile::getExtensionByMimeType($contentType);
			if ($extension !== null && TypeFile::isDocument('file.' . $extension))
			{
				return $this->canPreviewDocument($file, $fileId);
			}
		}

		$type = FileAttributes::resolveViewerTypeByFileModel($file, $contentType, $fileId, $storedFileData);

		// Both the stub and the size-restricted renderer report this type: no viewer renders the file.
		if ($type === Renderer\Stub::getJsType())
		{
			return false;
		}

		if ($type === Html::getJsType())
		{
			return false;
		}

		return !FileAttributes::requiresUnifiedLinkToOpen($type, $contentType, $file);
	}

	private function canPreviewDocument(File $file, int $fileId): bool
	{
		$storedFileData = $this->fileDataByFileId[$fileId] ?? null;
		if (array_key_exists($fileId, $this->fileDataByFileId) && $storedFileData === null)
		{
			return false;
		}

		$contentType = $storedFileData === null
			? TypeFile::getMimeTypeByFilename($file->getName())
			: TypeFile::normalizeMimeType($storedFileData['CONTENT_TYPE'], $file->getName())
		;
		$size = $storedFileData['FILE_SIZE'] ?? $file->getSize();
		if ($contentType === 'application/pdf')
		{
			return true;
		}

		if (in_array($contentType, Renderer\Code::getAllowedContentTypes(), true))
		{
			return $size <= Renderer\Code::getSizeRestriction();
		}

		if (!ModuleManager::isModuleInstalled('transformer'))
		{
			return false;
		}

		$this->transformerManager ??= new TransformerManager();
		$transformation = $this->transformerManager->buildTransformationByFile([
			'CONTENT_TYPE' => $contentType,
			'ORIGINAL_NAME' => $file->getName(),
		]);

		return $transformation !== null
			&& ($transformation->getInputMaxSize() <= 0 || $size <= $transformation->getInputMaxSize());
	}

	/**
	 * The warmed up set answers for a link; one left out of it falls back to the model, which resolves the
	 * object behind the link on its own.
	 */
	private function resolveFileId(File $file): int
	{
		$realObject = $this->realObjectResolver->resolve($file);

		return $realObject === null ? (int)$file->getFileId() : (int)$realObject->getFileId();
	}

}
