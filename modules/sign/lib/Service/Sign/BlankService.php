<?php

namespace Bitrix\Sign\Service\Sign;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Entity\AddResult;
use Bitrix\Main\Error;
use Bitrix\Main\IO\Path;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\SystemException;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Item\Blank;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\Fs\File;
use Bitrix\Sign\Item\Fs\FileCollection;
use Bitrix\Sign\Repository\Blank\ResourceRepository;
use Bitrix\Sign\Repository\BlankRepository;
use Bitrix\Sign\Repository\FileRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\BlankFileExtension;
use Bitrix\Sign\Type\BlankScenario;
use Bitrix\Sign\Upload\BlankUploadController;

class BlankService
{
	public const BLANK_TYPE_CONFIRMATION_REQUIRED = 'BLANK_TYPE_CONFIRMATION_REQUIRED';

	private BlankFileService $blankFileService;
	private BlankRepository $blankRepository;
	private FileRepository $fileRepository;
	private readonly ResourceRepository $resourceRepository;

	public function __construct(
		?BlankFileService $blankFileService = null,
		?BlankRepository $blankRepository = null,
		?FileRepository $fileRepository = null,
	)
	{
		$this->blankFileService = $blankFileService ?? Container::instance()
			->getSignBlankFileService();
		$this->blankRepository = $blankRepository ?? Container::instance()
			->getBlankRepository();
		$this->fileRepository = $fileRepository ?? Container::instance()
			->getFileRepository();
		$this->resourceRepository = Container::instance()->getBlankResourceRepository();
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function createFromFile(
		FileCollection $fileCollection,
		string $scenario = BlankScenario::B2B,
		bool $forTemplate = false,
		bool $hasPlaceholders = false,
		bool $autoDetectType = false,
	): AddResult
	{
		if (!in_array($scenario, BlankScenario::getAll(), true))
		{
			return (new AddResult())->addError(new Error('Wrong blank scenario'));
		}

		$fileCount = $fileCollection->count();
		if ($autoDetectType)
		{
			$validationResult = $this->validateAutoDetectionFiles($fileCollection, $scenario);
			if (!$validationResult->isSuccess())
			{
				return (new AddResult())->addErrors($validationResult->getErrors());
			}

			$hasPlaceholders = mb_strtolower((string)$fileCollection->first()->type) === 'docx';
		}

		$firstFile = $fileCollection->shift();
		if (is_null($firstFile))
		{
			return (new AddResult())->addError(new Error('Files sent with errors'));
		}

		$imagesCountLimit = Storage::instance()->getImagesCountLimitForBlankUpload();
		if ($firstFile->isImage && $fileCount > $imagesCountLimit)
		{
			return (new AddResult())->addError(
				new Error(
					Loc::getMessage('SIGN_SERVICE_BLANK_TOO_MANY_IMAGES', [
						'#COUNT#' => $imagesCountLimit,
					]),
					'FILE_TOO_MANY_IMAGES',
				),
			);
		}

		$preparedFileResult = $this->blankFileService->prepareFile($firstFile);
		if (!$preparedFileResult->isSuccess())
		{
			return (new AddResult())->addErrors($preparedFileResult->getErrors());
		}

		/** @var \Bitrix\Sign\File $resultFile */
		$resultFile = $preparedFileResult->getData()['file'];
		if ($firstFile->id !== null && $resultFile->getId() !== $firstFile->id)
		{
			$this->fileRepository->deleteById($firstFile->id);

			$firstFile = File::createByLegacyFile($resultFile);
		}

		$addResult = $this->blankRepository->add(
			new Blank(
				title: $firstFile->name,
				fileCollection: new FileCollection($firstFile),
				scenario: $scenario,
				forTemplate: $forTemplate,
				hasPlaceholders: $hasPlaceholders,
			),
		);
		$files = $fileCollection->toArray();

		if ($addResult->isSuccess() && $firstFile->isImage)
		{
			$result = $this->blankFileService->addImagesToBlank($addResult->getId(), $files);
			if (!$result->isSuccess())
			{
				return (new AddResult())->addErrors($result->getErrors());
			}
		}
		foreach ($fileCollection as $file)
		{
			if ($file->id !== null)
			{
				$this->fileRepository->deleteById($file->id);
			}
		}

		return $addResult;
	}

	/**
	 * @param array $files
	 * @param string $scenario
	 * @param bool $forTemplate
	 * @param bool $hasPlaceholders
	 * @param bool $autoDetectType
	 * @param bool|null $expectedHasPlaceholders
	 * @return AddResult
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function createFromFileIds(
		array  $files,
		string $scenario = BlankScenario::B2B,
		bool   $forTemplate = false,
		bool   $hasPlaceholders = false,
		bool $autoDetectType = false,
		?bool $expectedHasPlaceholders = null,
	): AddResult
	{
		if ($expectedHasPlaceholders !== null && (!$autoDetectType || $scenario !== BlankScenario::B2E))
		{
			return (new AddResult())->addError(new Error('Expected blank type requires automatic B2E detection'));
		}

		if (
			!in_array($scenario, BlankScenario::getAll(), true)
			|| ($autoDetectType && $scenario !== BlankScenario::B2E)
		)
		{
			return (new AddResult())->addError(new Error('Wrong blank scenario'));
		}

		if ($autoDetectType && !Loader::includeModule('ui'))
		{
			return (new AddResult())->addError(new Error('File uploader is unavailable'));
		}

		$fileController = new BlankUploadController([]);
		$uploader = new \Bitrix\UI\FileUploader\Uploader($fileController);

		// The tokens of the batch are read before the loop, not one by one inside it: a merged
		// document brings the whole image selection of the user here, and a damaged token must be
		// answered before any other token of the batch has been committed.
		try
		{
			$pendingFiles = $uploader->getPendingFiles($files);
		}
		catch (BadSignatureException $exception)
		{
			if (!$autoDetectType)
			{
				throw $exception;
			}

			return (new AddResult())->addError(new Error('Failed to save uploaded file'));
		}

		$fileCollection = new FileCollection();
		$storedPaths = [];

		foreach ($files as $fileId)
		{
			if (is_null($fileId))
			{
				return (new AddResult())->addError(new Error('Files sent with errors'));
			}

			$persistentFileId = null;
			if (is_string($fileId))
			{
				$pendingFile = $pendingFiles->get($fileId);
				if (!$autoDetectType)
				{
					// Explicit mode commits a token as soon as it is read; automatic mode waits for
					// the whole batch below, so a refused batch leaves every token of it reusable.
					$pendingFile?->makePersistent();
				}

				$persistentFileId = $pendingFile?->getFileId();
				if ($persistentFileId === null)
				{
					return (new AddResult())->addError(new Error('Failed to save uploaded file'));
				}
			}

			$signFile = new \Bitrix\Sign\File((int)($persistentFileId ?: $fileId));
			if ($autoDetectType && !$signFile->isExist())
			{
				return (new AddResult())->addError(new Error('Files sent with errors'));
			}

			$storedPath = (string)$signFile->getPath();
			$file = new File(
				$signFile->getName(),
				$storedPath === '' ? '' : Path::getDirectory($storedPath),
				$signFile->getExtension(),
				$signFile->getId(),
				null,
				$signFile->isImage(),
			);
			// The size comes from the b_file row and not from the content: the limits below are
			// stated in bytes, and measuring them by reading every file would hold the whole image
			// part of a batch in memory before a single limit has been checked.
			$file->size = $signFile->getSize();
			$fileCollection->addItem($file);
			$storedPaths[(int)$signFile->getId()] = $storedPath;
		}

		if ($autoDetectType)
		{
			$validationResult = $this->validateAutoDetectionFiles($fileCollection, $scenario);
			if (!$validationResult->isSuccess())
			{
				return (new AddResult())->addErrors($validationResult->getErrors());
			}

			foreach ($fileCollection as $file)
			{
				if ($file->isImage && !$this->isReadableImage($storedPaths[(int)$file->id] ?? ''))
				{
					return (new AddResult())->addError(new Error('Invalid file list', 'INVALID_FILE_LIST'));
				}
			}

			$actualHasPlaceholders = mb_strtolower((string)$fileCollection->first()->type) === 'docx';
			if ($expectedHasPlaceholders !== null && $expectedHasPlaceholders !== $actualHasPlaceholders)
			{
				return (new AddResult())->addError(new Error(
					'Confirm the detected blank type',
					self::BLANK_TYPE_CONFIRMATION_REQUIRED,
					['hasPlaceholders' => $actualHasPlaceholders],
				));
			}

			$pendingFiles->makePersistent();
		}

		return $this->createFromFile($fileCollection, $scenario, $forTemplate, $hasPlaceholders, $autoDetectType);
	}

	/**
	 * @param string $path Absolute path of the stored file, as the file array of its id gave it.
	 */
	private function isReadableImage(string $path): bool
	{
		try
		{
			$info = (new \Bitrix\Main\File\Image($path))->getInfo();

			return $info !== null
				&& $info->getWidth() > 0
				&& $info->getHeight() > 0
				&& in_array($info->getFormat(), [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)
			;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public function validateAutoDetectionFiles(FileCollection $files, string $scenario): Result
	{
		$result = new Result();
		if ($scenario !== BlankScenario::B2E)
		{
			return $result->addError(new Error('Wrong blank scenario'));
		}

		if ($files->empty())
		{
			return $result->addError(new Error('Files sent with errors'));
		}

		$storage = Storage::instance();
		$totalImageSize = 0;
		foreach ($files as $file)
		{
			if (!BlankFileExtension::isAllowed((string)$file->type))
			{
				Loc::loadMessages(__DIR__ . '/BlankFileService.php');

				return $result->addError(new Error(
					Loc::getMessage('SIGN_CORE_BLANK_ERROR_NOT_ALLOWED_EXTENSIONS'),
					'NOT_ALLOWED_EXTENSIONS',
				));
			}

			$isImageExtension = in_array(mb_strtolower((string)$file->type), ['jpg', 'jpeg', 'png'], true);
			if (($files->count() > 1 || $isImageExtension) && !$file->isImage)
			{
				return $result->addError(new Error('Invalid file list', 'INVALID_FILE_LIST'));
			}

			if ($file->isImage)
			{
				$totalImageSize += $file->size;
				if ($file->size > $storage->getUploadImagesMaxSize())
				{
					Loc::loadMessages(__DIR__ . '/BlankFileService.php');

					return $result->addError(new Error(
						Loc::getMessage('SIGN_SERVICE_SIGN_BLANKFILE_IMAGE_FILE_TOO_BIG', [
							'#SIZE#' => floor($storage->getUploadImagesMaxSize() / 1024 / 1024),
						]),
						'FILE_TOO_BIG',
					));
				}
			}
		}

		if ($files->first()->isImage && $files->count() > $storage->getImagesCountLimitForBlankUpload())
		{
			return $result->addError(new Error(
				Loc::getMessage('SIGN_SERVICE_BLANK_TOO_MANY_IMAGES', [
					'#COUNT#' => $storage->getImagesCountLimitForBlankUpload(),
				]),
				'FILE_TOO_MANY_IMAGES',
			));
		}

		if ($totalImageSize > $storage->getUploadTotalMaxSize())
		{
			Loc::loadMessages(__DIR__ . '/BlankFileService.php');

			return $result->addError(new Error(
				Loc::getMessage('SIGN_IMAGES_BLANK_ERROR_FILE_TOO_BIG', [
					'#SIZE#' => floor($storage->getUploadTotalMaxSize() / 1024 / 1024),
				]),
				'FILE_TOO_BIG',
			));
		}

		return $result;
	}

	/**
	 * Metadata of the files still pending in the uploader, keyed by token: the name and the byte
	 * size come from the temp file row, so a caller weighing or classifying a batch reads no file
	 * and, on a portal with cloud storage, downloads nothing.
	 *
	 * An unknown, foreign or expired token is absent from the result: such a file is rejected later,
	 * when the blank is created from it.
	 *
	 * @param array $files Uploader tokens.
	 * @return array<string, \Bitrix\UI\FileUploader\FileInfo>
	 */
	public function getUploadedFilesInfo(array $files): array
	{
		$tokens = array_values(array_filter(
			$files,
			static fn($fileId): bool => is_string($fileId) && $fileId !== '',
		));

		if ($tokens === [] || !Loader::includeModule('ui'))
		{
			return [];
		}

		$uploader = new \Bitrix\UI\FileUploader\Uploader(new BlankUploadController([]));

		$uploadedFiles = [];
		foreach ($uploader->load($tokens) as $loadResult)
		{
			$file = $loadResult->getFile();
			if ($file !== null)
			{
				$uploadedFiles[(string)$loadResult->getId()] = $file;
			}
		}

		return $uploadedFiles;
	}

	public function changeBlankTitleByDocument(Document $document, string $title): Result
	{
		if ($document->blankId === null)
		{
			return (new Result())->addError(new Error('Document has no connected blank'));
		}

		$blankItem = $this->blankRepository->getById($document->blankId);
		if ($blankItem === null)
		{
			return (new Result())->addError(new Error('No blank found for this document'));
		}

		$blankItem->title = $title;

		return $this->blankRepository->update($blankItem);
	}

    public function deleteWithResources(Blank $blankItem): Result
	{
        return $this->blankRepository->delete($blankItem->id);
    }

	public function rollbackById(int $blankId): Result
	{
		if (Container::instance()->getDocumentRepository()->getCountByBlankId($blankId) > 0)
		{
			return new Result();
		}

		return $this->blankRepository->delete($blankId);
	}

	public function getPreviewUrl(int $blankId): ?string
	{
		if ($blankId < 1)
		{
			return null;
		}

		$resource = $this->resourceRepository->getFirstByBlankId($blankId);
		if ($resource === null)
		{
			return null;
		}

		return $this->fileRepository->getFileSrc($resource->fileId);
	}

	public function getById(int $blankId): ?Blank
	{
		if ($blankId < 1)
		{
			return null;
		}

		return $this->blankRepository->getById($blankId);
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function hasPlaceholders(int $blankId): bool
	{
		return $this->blankRepository->hasPlaceholdersById($blankId);
	}

	public function hasDownloadableFile(?Blank $blank): bool
	{
		if ($blank?->fileCollection === null || $blank->fileCollection->count() === 0)
		{
			return false;
		}

		foreach ($blank->fileCollection as $file)
		{
			if (!\CFile::GetFileArray($file->id))
			{
				return false;
			}
		}

		return true;
	}
}
