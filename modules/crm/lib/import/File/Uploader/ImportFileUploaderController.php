<?php

namespace Bitrix\Crm\Import\File\Uploader;

use Bitrix\Crm\Import\Enum\Encoding;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Config\Ini;
use Bitrix\UI\FileUploader\Configuration;
use Bitrix\UI\FileUploader\FileInfo;
use Bitrix\UI\FileUploader\FileOwnershipCollection;
use Bitrix\UI\FileUploader\UploaderController;
use Bitrix\UI\FileUploader\UploadResult;
use CCrmOwnerType;
use CFile;

final class ImportFileUploaderController extends UploaderController
{
	private ?int $entityTypeId = null;
	private UserPermissions\EntityPermissions\Type $permissions;

	public function __construct(array $options = [])
	{
		parent::__construct($options);

		$this->entityTypeId = $this->getEntityTypeIdFromOptions($options);
		$this->permissions = Container::getInstance()->getUserPermissions()->entityType();
	}

	public function isAvailable(): bool
	{
		return $this->canImport();
	}

	public function getConfiguration(): Configuration
	{
		$configuration = new Configuration();

		$configuration->setAcceptedFileTypes(['.csv', '.txt', '.vcf']);
		$configuration->setMaxFileSize(Ini::getInt('upload_max_filesize'));

		return $configuration;
	}

	public function canUpload(): bool
	{
		return $this->canImport();
	}

	public function canView(): bool
	{
		return $this->canImport();
	}

	public function canRemove(): bool
	{
		return $this->canImport();
	}

	public function verifyFileOwner(FileOwnershipCollection $files): void
	{
	}

	public function onUploadComplete(UploadResult $uploadResult): void
	{
		$file = $uploadResult->getFileInfo();
		if ($file === null)
		{
			return;
		}

		$file->setCustomData([
			'detectedEncoding' => $this->detectEncoding($file),
		]);
	}

	private function detectEncoding(FileInfo $fileInfo): ?string
	{
		$file = CFile::MakeFileArray($fileInfo->getFileId());
		if (!is_array($file))
		{
			return null;
		}

		$filename = $file['tmp_name'] ?? '';
		if (
			!is_string($filename)
			|| empty($filename)
			|| !is_readable($filename)
		)
		{
			return null;
		}

		$handle = fopen($filename, 'rb');

		$bom = fread($handle, 3);
		$bomEncoding = $this->detectEncodingByBom($bom);
		if ($bomEncoding !== null)
		{
			fclose($handle);

			return $bomEncoding->value;
		}

		rewind($handle);
		$content = fgets($handle);
		fclose($handle);

		if (!is_string($content))
		{
			return null;
		}

		$availableEncodings = array_map(static fn (Encoding $encoding) => $encoding->value, Encoding::cases());
		$detectedEncoding = mb_detect_encoding($content, $availableEncodings, true);
		if ($detectedEncoding === false)
		{
			return null;
		}

		return Encoding::tryFromEncoding($detectedEncoding)?->value;
	}

	private function detectEncodingByBom(string|false $bom): ?Encoding
	{
		if (!is_string($bom) || strlen($bom) < 2)
		{
			return null;
		}

		if ($bom === "\xEF\xBB\xBF")
		{
			return Encoding::UTF8;
		}

		$firstTwo = $bom[0] . $bom[1];
		if ($firstTwo === "\xFE\xFF" || $firstTwo === "\xFF\xFE")
		{
			return Encoding::UTF16;
		}

		return null;
	}

	private function getEntityTypeIdFromOptions(array $options): ?int
	{
		$entityTypeId = $options['entityTypeId'] ?? null;
		if (!CCrmOwnerType::IsDefined($entityTypeId))
		{
			return null;
		}

		return (int)$entityTypeId;
	}

	private function canImport(): bool
	{
		return $this->entityTypeId !== null && $this->permissions->canImportItems($this->entityTypeId);
	}
}
