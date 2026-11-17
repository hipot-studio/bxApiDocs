<?php

declare(strict_types=1);

namespace Bitrix\Disk\FilePicker;

use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\Ui\FileAttributes;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\FileTable;
use Bitrix\Main\UI\Viewer\ItemAttributes;
use Bitrix\Main\UI\Viewer\Renderer;
use Bitrix\Main\UI\Viewer\Transformation\TransformerManager;

/**
 * Builds the viewer attributes of one file for the picker: viewing and downloading only, no action that
 * changes the file or its access. A file the picker must not open at all answers with null.
 */
class ViewerAttributesFactory
{
	/** @var array<int, array> Stored file record as the item sees it, by the id of that item. */
	private array $fileDataByObjectId = [];

	public function __construct(
		private readonly RealObjectResolver $realObjectResolver = new RealObjectResolver(),
	)
	{
	}

	/**
	 * Reads the stored file records of the batch in one query. The record goes to the file model itself, so
	 * that checks asking the model for it (the unified link support) reuse the same read.
	 *
	 * A link carries no stored file and no unique code of its own: both belong to the object behind it, and
	 * asking the link for either resolves that object anew. The objects of the whole batch are resolved in
	 * one query first, and they are what the records are handed to.
	 *
	 * @param array<int, File> $files
	 */
	public function preloadFileData(array $files): void
	{
		$this->realObjectResolver->warmUp($files);

		$itemsByFileId = [];
		foreach ($files as $file)
		{
			$fileId = (int)$this->resolveFileSource($file)->getFileId();
			if ($fileId > 0)
			{
				// One stored file may belong to several items of the same batch: a link and the object
				// behind it, or two links of one object. Every model of the batch is served by the read.
				$itemsByFileId[$fileId][] = $file;
			}
		}

		if (empty($itemsByFileId))
		{
			return;
		}

		foreach ($this->loadFileData(array_keys($itemsByFileId)) as $row)
		{
			$fileData = $this->asStoredRecord($row);
			foreach ($itemsByFileId[(int)$fileData['ID']] ?? [] as $file)
			{
				$itemData = $this->withItemName($fileData, (string)$file->getName());
				$this->fileDataByObjectId[(int)$file->getId()] = $itemData;
				// The model keeps the record as it is stored, with one exception: the refined content type,
				// because the unified link support of a document is judged by that type.
				$this->resolveFileSource($file)->primeFileData(
					['CONTENT_TYPE' => $itemData['CONTENT_TYPE']] + $fileData,
				);
			}
		}
	}

	protected function loadFileData(array $fileIds): array
	{
		return FileTable::getList([
			'select' => ['*'],
			'filter' => ['@ID' => $fileIds],
		])->fetchAll();
	}

	/**
	 * The row takes the shape CFile answers with: a record read through the orm carries typed values and
	 * knows nothing of file versions, while everything reading File::getFile() expects the strings and the
	 * keys of CFile::GetFileArray().
	 */
	private function asStoredRecord(array $row): array
	{
		$record = [];
		foreach ($row as $name => $value)
		{
			$record[$name] = $value === null ? null : (string)$value;
		}

		$record['VERSION_ORIGINAL_ID'] = null;
		$record['META'] = '';

		return $record;
	}

	/**
	 * The record as the item of the picker sees it: the name of the item in Disk, and the stored content
	 * type refined by that name, as on the write path. The listing judges an item by the very same name, so
	 * a renamed file and a link named of its own get one verdict on both paths.
	 */
	private function withItemName(array $fileData, string $itemName): array
	{
		if (empty($fileData['ID']))
		{
			return $fileData;
		}

		return [
			'ORIGINAL_NAME' => $itemName,
			'CONTENT_TYPE' => TypeFile::normalizeMimeType((string)($fileData['CONTENT_TYPE'] ?? ''), $itemName),
		] + $fileData;
	}

	/**
	 * The model the stored file and the unified link of an item are asked about: the object behind a link,
	 * the item itself otherwise. A link left out of the resolved batch answers for itself, as before.
	 */
	private function resolveFileSource(File $file): File
	{
		return $this->realObjectResolver->resolve($file) ?? $file;
	}

	public function build(File $file, SecurityContext $securityContext): ?array
	{
		$canDownload = $file->canDownload($securityContext);
		$sourceUrl = $canDownload
			? $this->getUrlForDownloadFile($file)
			: $this->getUrlForShowFile($file)
		;

		$attributes = $this->buildItemAttributes($file, $sourceUrl);
		if ($attributes === null)
		{
			return null;
		}

		// The stub and the size-restricted renderer share this type: no renderer shows the file, so the
		// viewer would open on a placeholder. The listing flag is optimistic and this answer corrects it.
		if ($attributes->getViewerType() === Renderer\Stub::getJsType())
		{
			return null;
		}

		if ($attributes->issetAttribute(FileAttributes::ATTRIBUTE_SEPARATE_ITEM))
		{
			return null;
		}

		$attributes
			->setAttribute(FileAttributes::ATTRIBUTE_OBJECT_ID, (int)$file->getId())
			->setTitle($file->getName())
			->clearActions()
		;

		if (!$canDownload)
		{
			$attributes->setAttribute(FileAttributes::ATTRIBUTE_DOWNLOAD_DISABLED, 'true');
		}

		if ($attributes instanceof FileAttributes)
		{
			$attributes->setUnifiedLinkDisabled();
		}

		return $this->toStringMap($attributes->toVueBind());
	}

	/**
	 * The client applies the map with setAttribute() and the viewer reads it back through node.dataset,
	 * where every value is a string: a flag judged by its value (separate item) needs 'true', while one
	 * judged by presence keeps an empty string, and a flag that is off is simply not sent.
	 *
	 * @return array<string, string>
	 */
	private function toStringMap(array $attributes): array
	{
		$stringMap = [];
		foreach ($attributes as $name => $value)
		{
			if ($value === false)
			{
				continue;
			}

			$stringMap[(string)$name] = match (true)
			{
				$value === true => 'true',
				$value === null => '',
				default => (string)$value,
			};
		}

		return $stringMap;
	}

	/**
	 * A file without its stored record is skipped like an unreadable one. The record comes from the batch;
	 * a file left out of it is read by its own model and shaped for the item here instead.
	 *
	 * A document is built as a plain viewer item: the Disk attributes would turn it into a separate item
	 * (cloud document, unified link), and the picker offers the embedded viewer only. The record and the
	 * model handed to the attributes are those of the object behind a link, because the stored file belongs
	 * to that object. The item the picker answers about stays the link itself: its id, its name, its right
	 * to download and its urls.
	 */
	protected function buildItemAttributes(File $file, string $sourceUrl): ?ItemAttributes
	{
		$source = $this->resolveFileSource($file);
		$fileData = $this->fileDataByObjectId[(int)$file->getId()]
			?? $this->withItemName($source->getFile() ?? [], (string)$file->getName())
		;
		if (empty($fileData['ID']))
		{
			return null;
		}

		try
		{
			if ($this->usesEmbeddedDocumentViewer($file, $fileData))
			{
				if ($this->isDocumentRestrictedByTransformationSize($fileData))
				{
					return null;
				}

				return ItemAttributes::buildByFileData($fileData, $sourceUrl);
			}

			return FileAttributes::buildByFileData($this->withFileObject($fileData, $source), $sourceUrl);
		}
		catch (ArgumentException)
		{
			return null;
		}
	}

	private function usesEmbeddedDocumentViewer(File $file, array $fileData): bool
	{
		if (TypeFile::isDocument($file->getName()))
		{
			return true;
		}

		if (GetFileExtension($file->getName()) !== '')
		{
			return false;
		}

		$extension = TypeFile::getExtensionByMimeType((string)($fileData['CONTENT_TYPE'] ?? ''));

		return $extension !== null && TypeFile::isDocument('file.' . $extension);
	}

	private function isDocumentRestrictedByTransformationSize(array $fileData): bool
	{
		if ((string)($fileData['CONTENT_TYPE'] ?? '') === 'application/pdf')
		{
			return false;
		}

		$transformerManager = new TransformerManager();
		if (!$transformerManager->isAvailable())
		{
			return false;
		}

		$transformation = $transformerManager->buildTransformationByFile($fileData);
		if ($transformation === null)
		{
			return false;
		}

		$maxSize = $transformation->getInputMaxSize();

		return $maxSize > 0 && (int)($fileData['FILE_SIZE'] ?? 0) > $maxSize;
	}

	private function withFileObject(array $fileData, File $file): array
	{
		$fileData[FileAttributes::KEY_FILE_OBJECT] = $file;
		$fileData[FileAttributes::KEY_VERSION_OBJECT] = null;

		return $fileData;
	}

	protected function getUrlForDownloadFile(File $file): string
	{
		return (string)Driver::getInstance()->getUrlManager()->getUrlForDownloadFile($file);
	}

	protected function getUrlForShowFile(File $file): string
	{
		return (string)Driver::getInstance()->getUrlManager()->getUrlForShowFile($file);
	}
}
