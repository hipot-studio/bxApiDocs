<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Configuration;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Internal\Service\TiffPreview\ErrorCode;
use Bitrix\Disk\Internal\Service\TiffPreview\PreviewStatus;
use Bitrix\Disk\QuickAccess\FileDataParameterService;
use Bitrix\Disk\Version;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine;
use Bitrix\Main\Engine\Response\BFile;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UI\Viewer\FilePreviewTable;
use Bitrix\Main\UI\Viewer\PreviewManager;
use Bitrix\Main\Web\Uri;
use Psr\Log\LoggerInterface;

Loc::loadMessages(__FILE__);

final class TiffPreviewService
{
	private const MAX_SOURCE_FILE_SIZE = 100 * 1024 * 1024;
	private const MAX_IMAGE_PIXELS = 50_000_000;
	private const MAX_IMAGE_SIDE = 4096;
	private const MAX_CONVERSION_TIME = 15;
	private const MAX_IMAGICK_MEMORY = 128 * 1024 * 1024;
	private const MAX_IMAGICK_MAP = 256 * 1024 * 1024;
	private const MAX_IMAGICK_DISK = 512 * 1024 * 1024;
	private const MAX_IMAGICK_THREADS = 1;
	private const CONVERSION_SLOT_COUNT = 2;
	private const LOCK_WAIT_TIME = 0;
	private const CONVERSION_SLOT_LOCK_PREFIX = 'disk_tiff_preview_slot_';
	private const PREVIEW_TOKEN_SALT = 'disk.tiff.preview';
	private const PREVIEW_TOKEN_PARAM = 'previewToken';
	private const CONVERSION_ERROR_CACHE_TTL = 3600;
	private const CONVERSION_ERROR_CACHE_DIR = '/disk/tiff_preview/errors';
	private const PREVIEW_TOUCH_INTERVAL = 86400;

	private PreviewManager $previewManager;
	private Connection $connection;
	private Signer $signer;
	private ?FileDataParameterService $fileDataParameterService;
	private LoggerInterface $logger;

	public function __construct(
		?PreviewManager $previewManager = null,
		?Connection $connection = null,
		?Signer $signer = null,
		?FileDataParameterService $fileDataParameterService = null,
		?LoggerInterface $logger = null,
	)
	{
		$this->previewManager = $previewManager ?? new PreviewManager();
		$this->connection = $connection ?? Application::getConnection();
		$this->signer = $signer ?? new Signer();
		$this->fileDataParameterService = $fileDataParameterService;
		$this->logger = $logger ?? LoggerFactory::create(
			id: 'tiff-preview',
			feature: 'tiff-preview',
		);
	}

	public function getByFile(
		File $file,
		?string $previewToken = null,
		?string $unifiedLinkSignature = null,
	): Result
	{
		$actionParams = ['fileId' => $file->getId()];
		if ($unifiedLinkSignature !== null)
		{
			$actionParams['_uls'] = $unifiedLinkSignature;
		}

		$urlManager = Driver::getInstance()->getUrlManager();
		$previewActionUrl = $urlManager->getUrlForShowTiffPreview($file);
		$quickDeliveryUrl = $urlManager->getUrlForDownloadFile($file);
		if ($unifiedLinkSignature !== null)
		{
			$previewActionUrl = $this->addUrlParams(
				$previewActionUrl,
				['_uls' => $unifiedLinkSignature],
			);
			$quickDeliveryUrl = $this->addUrlParams(
				$quickDeliveryUrl,
				['_uls' => $unifiedLinkSignature],
			);
		}

		return $this->getBySource(
			(int)$file->getFileId(),
			'file',
			$file->getName(),
			$this->buildActionUrl('disk.file.download', $actionParams),
			$previewActionUrl,
			$quickDeliveryUrl,
			$previewToken,
		);
	}

	public function getByVersion(Version $version, ?string $previewToken = null): Result
	{
		$file = $version->getObject();
		$quickDeliveryUrl = $file === null
			? ''
			: Driver::getInstance()->getUrlManager()->getUrlForDownloadFile($file);

		return $this->getBySource(
			(int)$version->getFileId(),
			'version',
			$version->getName(),
			$this->buildActionUrl('disk.version.download', ['versionId' => $version->getId()]),
			Driver::getInstance()->getUrlManager()->getUrlForShowTiffPreviewVersion((int)$version->getId()),
			$quickDeliveryUrl,
			$previewToken,
		);
	}

	public function getByExternalLink(
		File|Version $source,
		string $downloadUrl,
		string $previewActionUrl,
		string $quickDeliveryUrl,
		?string $previewToken = null,
	): Result
	{
		return $this->getBySource(
			(int)$source->getFileId(),
			'externalLink',
			$source->getName(),
			$downloadUrl,
			$previewActionUrl,
			$quickDeliveryUrl,
			$previewToken,
		);
	}

	public function getByAttachedObject(
		AttachedObject $attachedObject,
		?string $previewToken = null,
	): Result
	{
		if ($attachedObject->isSpecificVersion())
		{
			$version = $attachedObject->getVersion();
			if ($version === null)
			{
				return $this->createFailureResult(ErrorCode::CONVERSION_FAILED);
			}

			$sourceFileId = (int)$version->getFileId();
		}
		else
		{
			$file = $attachedObject->getFile();
			if ($file === null)
			{
				return $this->createFailureResult(ErrorCode::CONVERSION_FAILED);
			}

			$sourceFileId = (int)$file->getFileId();
		}

		return $this->getBySource(
			$sourceFileId,
			'attachedObject',
			$attachedObject->getName(),
			$this->buildActionUrl(
				'disk.attachedObject.download',
				['attachedObjectId' => $attachedObject->getId()],
			),
			Driver::getInstance()->getUrlManager()->getUrlForShowTiffPreviewAttached(
				(int)$attachedObject->getId(),
			),
			$this->buildActionUrl(
				'disk.attachedObject.download',
				['attachedObjectId' => $attachedObject->getId()],
			),
			$previewToken,
		);
	}

	private function getBySource(
		int $sourceFileId,
		string $contextType,
		string $displayName,
		string $downloadUrl,
		string $previewActionUrl,
		string $quickDeliveryUrl,
		?string $previewToken,
	): Result
	{
		try
		{
			return $this->processSource(
				$sourceFileId,
				$contextType,
				$displayName,
				$downloadUrl,
				$previewActionUrl,
				$quickDeliveryUrl,
				$previewToken,
			);
		}
		catch (\Throwable $exception)
		{
			$this->logEvent(ErrorCode::CONVERSION_FAILED, [
				'sourceFileId' => $sourceFileId,
				'contextType' => $contextType,
				'hasPreviewToken' => $previewToken !== null,
				'exceptionClass' => $exception::class,
			], 'error');

			return $previewToken === null
				? $this->createErrorResult(ErrorCode::CONVERSION_FAILED, $downloadUrl)
				: $this->createFailureResult(ErrorCode::CONVERSION_FAILED);
		}
	}

	private function processSource(
		int $sourceFileId,
		string $contextType,
		string $displayName,
		string $downloadUrl,
		string $previewActionUrl,
		string $quickDeliveryUrl,
		?string $previewToken,
	): Result
	{
		if ($previewToken !== null)
		{
			return $this->createPreviewResponse($sourceFileId, $contextType, $displayName, $previewToken);
		}

		$previewRow = $this->getPreviewRow($sourceFileId);
		if ($this->isPreviewReady($previewRow))
		{
			$previewImageId = (int)$previewRow['PREVIEW_IMAGE_ID'];
			$this->logEvent('ready_from_cache', [
				'sourceFileId' => $sourceFileId,
				'contextType' => $contextType,
				'previewImageId' => $previewImageId,
			]);

			return $this->createReadyResult(
				$sourceFileId,
				$contextType,
				$previewRow,
				$previewImageId,
				$previewActionUrl,
				$quickDeliveryUrl,
				$downloadUrl,
			);
		}

		$sourceFile = \CFile::getFileArray($sourceFileId);
		if (!is_array($sourceFile))
		{
			$this->logEvent(ErrorCode::CONVERSION_FAILED, [
				'sourceFileId' => $sourceFileId,
				'contextType' => $contextType,
			], 'warning');

			return $this->createErrorResult(ErrorCode::CONVERSION_FAILED, $downloadUrl);
		}

		$validationError = $this->validateSource($sourceFile);
		if ($validationError !== null)
		{
			$this->logEvent($validationError, [
				'sourceFileId' => $sourceFileId,
				'contextType' => $contextType,
			], 'warning');

			return $this->createErrorResult($validationError, $downloadUrl);
		}

		$cachedErrorCode = $this->getCachedConversionError($sourceFile);
		if ($cachedErrorCode !== null)
		{
			$this->logEvent('error_from_cache', [
				'sourceFileId' => $sourceFileId,
				'contextType' => $contextType,
				'errorCode' => $cachedErrorCode,
			], 'warning');

			return $this->createErrorResult($cachedErrorCode, $downloadUrl);
		}

		$lockName = 'disk_tiff_preview_' . $sourceFileId;
		try
		{
			$isLocked = $this->connection->lock($lockName, self::LOCK_WAIT_TIME);
		}
		catch (\Throwable $exception)
		{
			$this->logEvent(ErrorCode::CONVERSION_FAILED, [
				'sourceFileId' => $sourceFileId,
				'contextType' => $contextType,
				'exceptionClass' => $exception::class,
			], 'error');

			return $this->createErrorResult(ErrorCode::CONVERSION_FAILED, $downloadUrl);
		}

		if (!$isLocked)
		{
			$previewRow = $this->getPreviewRow($sourceFileId);
			if ($this->isPreviewReady($previewRow))
			{
				$previewImageId = (int)$previewRow['PREVIEW_IMAGE_ID'];
				$this->logEvent('ready_from_cache', [
					'sourceFileId' => $sourceFileId,
					'contextType' => $contextType,
					'previewImageId' => $previewImageId,
					'lockBusy' => true,
				]);

				return $this->createReadyResult(
					$sourceFileId,
					$contextType,
					$previewRow,
					$previewImageId,
					$previewActionUrl,
					$quickDeliveryUrl,
					$downloadUrl,
				);
			}

			$this->logEvent('preparing_lock_busy', [
				'sourceFileId' => $sourceFileId,
				'contextType' => $contextType,
			]);

			return $this->createPreparingResult($downloadUrl);
		}

		$conversionSlotLockName = null;
		try
		{
			$previewRow = $this->getPreviewRow($sourceFileId);
			if ($this->isPreviewReady($previewRow))
			{
				$previewImageId = (int)$previewRow['PREVIEW_IMAGE_ID'];
				$this->logEvent('ready_from_cache', [
					'sourceFileId' => $sourceFileId,
					'contextType' => $contextType,
					'previewImageId' => $previewImageId,
					'afterLock' => true,
				]);

				return $this->createReadyResult(
					$sourceFileId,
					$contextType,
					$previewRow,
					$previewImageId,
					$previewActionUrl,
					$quickDeliveryUrl,
					$downloadUrl,
				);
			}
			if (!$this->clearStalePreview($previewRow))
			{
				$this->logEvent(ErrorCode::CONVERSION_FAILED, [
					'sourceFileId' => $sourceFileId,
					'contextType' => $contextType,
					'reason' => 'stale_preview_cleanup_failed',
				], 'warning');

				return $this->createErrorResult(ErrorCode::CONVERSION_FAILED, $downloadUrl);
			}

			$conversionSlotLockName = $this->acquireConversionSlot();
			if ($conversionSlotLockName === null)
			{
				$this->logEvent('preparing_capacity_busy', [
					'sourceFileId' => $sourceFileId,
					'contextType' => $contextType,
				]);

				return $this->createPreparingResult($downloadUrl);
			}

			// The first TIFF conversion is synchronous by the current ADR/SDD scope.
			// A non-blocking path needs a separate persistent queue design: in-request
			// background jobs still occupy the PHP worker, and CAgent was not chosen as
			// an ad hoc per-file queue.
			$conversionResult = $this->convertFirstPage($sourceFileId, $displayName);
			if (!$conversionResult->isSuccess())
			{
				$errorCode = $this->normalizeErrorCode((string)$conversionResult->getError()?->getCode());
				$this->logEvent($errorCode, [
					'sourceFileId' => $sourceFileId,
					'contextType' => $contextType,
				], 'warning');

				// The short TTL cache protects the conversion slots; the full state model belongs
				// to a persistent TIFF conversion queue with retries and source-file invalidation.
				$this->saveConversionErrorCache($sourceFile, $errorCode);
				return $this->createErrorResult($errorCode, $downloadUrl);
			}

			$previewRow = $this->getPreviewRow($sourceFileId);
			if (!$this->isPreviewReady($previewRow))
			{
				$this->logEvent(ErrorCode::CONVERSION_FAILED, [
					'sourceFileId' => $sourceFileId,
					'contextType' => $contextType,
				], 'error');

				return $this->createErrorResult(ErrorCode::CONVERSION_FAILED, $downloadUrl);
			}

			$this->logEvent('created', [
				'sourceFileId' => $sourceFileId,
				'contextType' => $contextType,
				'previewImageId' => (int)$previewRow['PREVIEW_IMAGE_ID'],
			]);

			return $this->createReadyResult(
				$sourceFileId,
				$contextType,
				$previewRow,
				(int)$previewRow['PREVIEW_IMAGE_ID'],
				$previewActionUrl,
				$quickDeliveryUrl,
				$downloadUrl,
			);
		}
		finally
		{
			if ($conversionSlotLockName !== null)
			{
				$this->releaseLock($conversionSlotLockName);
			}
			$this->releaseLock($lockName);
		}
	}

	private function validateSource(array $sourceFile): ?string
	{
		$extension = mb_strtolower((string)pathinfo((string)($sourceFile['ORIGINAL_NAME'] ?? ''), PATHINFO_EXTENSION));
		if (!in_array($extension, ['tif', 'tiff'], true))
		{
			return ErrorCode::TIFF_UNSUPPORTED;
		}

		if ((int)($sourceFile['FILE_SIZE'] ?? 0) > self::MAX_SOURCE_FILE_SIZE)
		{
			return ErrorCode::FILE_TOO_LARGE;
		}

		if (!extension_loaded('imagick') || !class_exists(\Imagick::class))
		{
			return ErrorCode::IMAGICK_UNAVAILABLE;
		}

		try
		{
			$supportedFormats = \Imagick::queryFormats('TIFF*');
		}
		catch (\Throwable)
		{
			return ErrorCode::TIFF_UNSUPPORTED;
		}

		return empty($supportedFormats) ? ErrorCode::TIFF_UNSUPPORTED : null;
	}

	private function convertFirstPage(int $sourceFileId, string $displayName): Result
	{
		$startedAt = microtime(true);
		$image = null;
		$tempFilePath = '';
		$previousImagickResourceLimits = [];
		$previewImageId = 0;
		$isPreviewAttached = false;

		try
		{
			$sourceFile = \CFile::makeFileArray($sourceFileId);
			$sourceFilePath = is_array($sourceFile) ? (string)($sourceFile['tmp_name'] ?? '') : '';
			if ($sourceFilePath === '' || !is_file($sourceFilePath))
			{
				return $this->createFailureResult(ErrorCode::CONVERSION_FAILED);
			}

			if (!$this->hasValidTiffSignature($sourceFilePath))
			{
				return $this->createFailureResult(ErrorCode::TIFF_UNSUPPORTED);
			}

			$tiffSourcePath = 'TIFF:' . $sourceFilePath . '[0]';
			$previousImagickResourceLimits = $this->configureImagickResourceLimits();
			$image = new \Imagick();
			$image->pingImage($tiffSourcePath);
			if ($this->hasTooManyPixels($image))
			{
				return $this->createFailureResult(ErrorCode::IMAGE_TOO_LARGE);
			}
			if ($this->isTimedOut($startedAt))
			{
				return $this->createFailureResult(ErrorCode::TIMEOUT);
			}

			$image->clear();
			$image->readImage($tiffSourcePath);
			if ($this->hasTooManyPixels($image))
			{
				return $this->createFailureResult(ErrorCode::IMAGE_TOO_LARGE);
			}
			if ($this->isTimedOut($startedAt))
			{
				return $this->createFailureResult(ErrorCode::TIMEOUT);
			}

			if (max($image->getImageWidth(), $image->getImageHeight()) > self::MAX_IMAGE_SIDE)
			{
				$image->thumbnailImage(self::MAX_IMAGE_SIDE, self::MAX_IMAGE_SIDE, true);
			}

			$image->setImageFormat('png');
			$image->setImagePage(0, 0, 0, 0);
			$image->stripImage();

			$tempFilePath = \CTempFile::GetFileName('tiff-preview.png');
			if (!CheckDirPath($tempFilePath) || !$image->writeImage($tempFilePath))
			{
				return $this->createFailureResult(ErrorCode::CONVERSION_FAILED);
			}
			if ($this->isTimedOut($startedAt))
			{
				return $this->createFailureResult(ErrorCode::TIMEOUT);
			}

			$previewFile = \CFile::makeFileArray($tempFilePath, 'image/png');
			if (!is_array($previewFile))
			{
				return $this->createFailureResult(ErrorCode::CONVERSION_FAILED);
			}

			$previewFile['name'] = $this->buildPreviewName($displayName);
			$previewFile['type'] = 'image/png';
			$previewFile['MODULE_ID'] = 'main';
			$previewImageId = (int)\CFile::saveFile($previewFile, 'main_preview', true, true);
			if ($previewImageId <= 0)
			{
				return $this->createFailureResult(ErrorCode::CONVERSION_FAILED);
			}
			if ($this->isTimedOut($startedAt))
			{
				return $this->createFailureResult(ErrorCode::TIMEOUT);
			}

			$attachResult = $this->previewManager->setPreviewImageId($sourceFileId, $previewImageId);
			if (!$attachResult->isSuccess())
			{
				return $this->createFailureResult(ErrorCode::CONVERSION_FAILED);
			}
			$isPreviewAttached = true;

			return (new Result())->setData(['previewImageId' => $previewImageId]);
		}
		catch (\Throwable $exception)
		{
			$errorCode = $this->isTimedOut($startedAt, $exception)
				? ErrorCode::TIMEOUT
				: ErrorCode::CONVERSION_FAILED;

			return $this->createFailureResult($errorCode);
		}
		finally
		{
			if ($image instanceof \Imagick)
			{
				$image->clear();
				$image->destroy();
			}
			if ($tempFilePath !== '' && is_file($tempFilePath))
			{
				unlink($tempFilePath);
			}
			if ($previewImageId > 0 && !$isPreviewAttached)
			{
				\CFile::delete($previewImageId);
			}
			$this->restoreImagickResourceLimits($previousImagickResourceLimits);
		}
	}

	private function hasValidTiffSignature(string $sourceFilePath): bool
	{
		$signature = file_get_contents($sourceFilePath, false, null, 0, 4);
		if (!is_string($signature))
		{
			return false;
		}

		return in_array($signature, [
			"II*\x00",
			"MM\x00*",
			"II+\x00",
			"MM\x00+",
		], true);
	}

	private function createPreviewResponse(
		int $sourceFileId,
		string $contextType,
		string $displayName,
		string $previewToken,
	): Result
	{
		$previewRow = $this->getPreviewRow($sourceFileId);
		$previewImageId = (int)($previewRow['PREVIEW_IMAGE_ID'] ?? 0);
		if (
			!$this->isPreviewReady($previewRow)
			|| !$this->isValidPreviewToken($sourceFileId, $previewImageId, $previewToken)
		)
		{
			$this->logEvent(ErrorCode::CONVERSION_FAILED, [
				'sourceFileId' => $sourceFileId,
				'contextType' => $contextType,
				'hasPreviewToken' => true,
				'reason' => 'invalid_preview_token',
			], 'warning');

			return $this->createFailureResult(ErrorCode::CONVERSION_FAILED);
		}

		if (!$this->touchPreview($previewRow))
		{
			$this->logEvent(ErrorCode::CONVERSION_FAILED, [
				'sourceFileId' => $sourceFileId,
				'contextType' => $contextType,
				'previewImageId' => $previewImageId,
				'reason' => 'touch_preview_failed',
			], 'warning');

			return $this->createFailureResult(ErrorCode::CONVERSION_FAILED);
		}

		try
		{
			$response = BFile::createByFileId($previewImageId, $this->buildPreviewName($displayName));
			$response
				->showInline(true)
				->setCacheTime(Configuration::DEFAULT_CACHE_TIME)
			;
		}
		catch (\Throwable)
		{
			$this->logEvent(ErrorCode::CONVERSION_FAILED, [
				'sourceFileId' => $sourceFileId,
				'contextType' => $contextType,
				'previewImageId' => $previewImageId,
				'reason' => 'preview_response_failed',
			], 'error');

			return $this->createFailureResult(ErrorCode::CONVERSION_FAILED);
		}

		return (new Result())->setData(['response' => $response]);
	}

	private function createReadyResult(
		int $sourceFileId,
		string $contextType,
		array $previewRow,
		int $previewImageId,
		string $previewActionUrl,
		string $quickDeliveryUrl,
		string $downloadUrl,
	): Result
	{
		if (!$this->touchPreview($previewRow))
		{
			$this->logEvent(ErrorCode::CONVERSION_FAILED, [
				'sourceFileId' => $sourceFileId,
				'contextType' => $contextType,
				'previewImageId' => $previewImageId,
				'reason' => 'ready_preview_touch_failed',
			], 'warning');

			return $this->createErrorResult(ErrorCode::CONVERSION_FAILED, $downloadUrl);
		}

		$previewToken = $this->signer->sign(
			$this->buildPreviewTokenValue($sourceFileId, $previewImageId),
			self::PREVIEW_TOKEN_SALT,
		);
		$encryptedPreviewData = $this->getEncryptedPreviewData($quickDeliveryUrl, $previewImageId);
		$previewUrl = $encryptedPreviewData === null
			? null
			: $this->addUrlParams(
				$quickDeliveryUrl,
				[
					FileDataParameterService::PARAMETER_NAME => $encryptedPreviewData,
					'ibxShowImage' => '1',
				],
			);

		if ($previewUrl === null)
		{
			$previewUrl = $this->addUrlParams(
				$previewActionUrl,
				[self::PREVIEW_TOKEN_PARAM => $previewToken],
			);
		}

		return (new Result())->setData([
			'status' => PreviewStatus::READY,
			'previewUrl' => $previewUrl,
			'downloadUrl' => null,
			'errorCode' => null,
			'message' => null,
		]);
	}

	private function getEncryptedPreviewData(
		string $quickDeliveryUrl,
		int $previewImageId,
	): ?string
	{
		if ($quickDeliveryUrl === '')
		{
			return null;
		}

		try
		{
			$this->fileDataParameterService ??= ServiceLocator::getInstance()->get(
				'disk.fileDataParameterService',
			);

			return $this->fileDataParameterService->getEncryptedFileData($previewImageId);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function createPreparingResult(string $downloadUrl): Result
	{
		return (new Result())->setData([
			'status' => PreviewStatus::PREPARING,
			'previewUrl' => null,
			'downloadUrl' => $downloadUrl,
			'errorCode' => null,
			'message' => null,
		]);
	}

	private function createErrorResult(string $errorCode, string $downloadUrl): Result
	{
		$errorCode = $this->normalizeErrorCode($errorCode);

		return (new Result())->setData([
			'status' => PreviewStatus::ERROR,
			'previewUrl' => null,
			'downloadUrl' => $downloadUrl,
			'errorCode' => $errorCode,
			'message' => $this->getErrorMessage($errorCode),
		]);
	}

	private function createFailureResult(string $errorCode): Result
	{
		$errorCode = $this->normalizeErrorCode($errorCode);

		return (new Result())->addError(new Error($this->getErrorMessage($errorCode), $errorCode));
	}

	private function getPreviewRow(int $sourceFileId): ?array
	{
		return $this->previewManager->getFilePreviewEntryByFileId($sourceFileId);
	}

	private function isPreviewReady(?array $previewRow): bool
	{
		$previewImageId = (int)($previewRow['PREVIEW_IMAGE_ID'] ?? 0);
		if ($previewImageId <= 0)
		{
			return false;
		}

		return is_array(\CFile::getFileArray($previewImageId));
	}

	private function getCachedConversionError(array $sourceFile): ?string
	{
		$cache = Cache::createInstance();
		if (!$cache->initCache(
			self::CONVERSION_ERROR_CACHE_TTL,
			$this->buildConversionErrorCacheId($sourceFile),
			self::CONVERSION_ERROR_CACHE_DIR,
		))
		{
			return null;
		}

		$vars = $cache->getVars();
		$errorCode = is_array($vars) ? (string)($vars['errorCode'] ?? '') : '';

		return $errorCode === '' ? null : $this->normalizeErrorCode($errorCode);
	}

	private function saveConversionErrorCache(array $sourceFile, string $errorCode): void
	{
		$cache = Cache::createInstance();
		if (!$cache->startDataCache(
			self::CONVERSION_ERROR_CACHE_TTL,
			$this->buildConversionErrorCacheId($sourceFile),
			self::CONVERSION_ERROR_CACHE_DIR,
		))
		{
			return;
		}

		$cache->endDataCache([
			'errorCode' => $this->normalizeErrorCode($errorCode),
		]);
	}

	private function buildConversionErrorCacheId(array $sourceFile): string
	{
		$cacheContext = [
			'id' => (int)($sourceFile['ID'] ?? 0),
			'fileSize' => (int)($sourceFile['FILE_SIZE'] ?? 0),
			'timestamp' => (string)($sourceFile['TIMESTAMP_X'] ?? ''),
			'subdir' => (string)($sourceFile['SUBDIR'] ?? ''),
			'fileName' => (string)($sourceFile['FILE_NAME'] ?? ''),
			'contentType' => (string)($sourceFile['CONTENT_TYPE'] ?? ''),
		];

		return 'conversion_error_' . sha1(serialize($cacheContext));
	}

	// FilePreviewTable cleanup intentionally deletes preview files; stale IDs are cache misses and are regenerated.
	private function clearStalePreview(?array $previewRow): bool
	{
		$previewImageId = (int)($previewRow['PREVIEW_IMAGE_ID'] ?? 0);
		if ($previewImageId <= 0 || $this->isPreviewReady($previewRow))
		{
			return true;
		}

		$previewRowId = (int)($previewRow['ID'] ?? 0);
		if ($previewRowId <= 0)
		{
			return false;
		}

		return FilePreviewTable::update($previewRowId, ['PREVIEW_IMAGE_ID' => null])->isSuccess();
	}

	private function touchPreview(array $previewRow): bool
	{
		$previewRowId = (int)($previewRow['ID'] ?? 0);
		if ($previewRowId <= 0)
		{
			return false;
		}

		$touchedAt = $previewRow['TOUCHED_AT'] ?? null;
		if ($touchedAt instanceof DateTime && $touchedAt->getTimestamp() >= time() - self::PREVIEW_TOUCH_INTERVAL)
		{
			return true;
		}

		return FilePreviewTable::update($previewRowId, ['TOUCHED_AT' => new DateTime()])->isSuccess();
	}

	private function releaseLock(string $lockName): void
	{
		try
		{
			$this->connection->unlock($lockName);
		}
		catch (\Throwable)
		{
			return;
		}
	}

	private function acquireConversionSlot(): ?string
	{
		for ($slot = 0; $slot < self::CONVERSION_SLOT_COUNT; $slot++)
		{
			$lockName = self::CONVERSION_SLOT_LOCK_PREFIX . $slot;
			if ($this->connection->lock($lockName, 0))
			{
				return $lockName;
			}
		}

		return null;
	}

	private function configureImagickResourceLimits(): array
	{
		$limits = [
			'Imagick::RESOURCETYPE_MEMORY' => self::MAX_IMAGICK_MEMORY,
			'Imagick::RESOURCETYPE_MAP' => self::MAX_IMAGICK_MAP,
			'Imagick::RESOURCETYPE_DISK' => self::MAX_IMAGICK_DISK,
			'Imagick::RESOURCETYPE_THREAD' => self::MAX_IMAGICK_THREADS,
			'Imagick::RESOURCETYPE_TIME' => self::MAX_CONVERSION_TIME,
		];
		$previousLimits = [];

		try
		{
			foreach ($limits as $resourceTypeName => $maximumLimit)
			{
				if (!defined($resourceTypeName))
				{
					continue;
				}

				$resourceType = (int)constant($resourceTypeName);
				$previousLimit = (int)\Imagick::getResourceLimit($resourceType);
				$limit = $previousLimit > 0
					? min($previousLimit, $maximumLimit)
					: $maximumLimit;

				$previousLimits[$resourceType] = $previousLimit;
				if (!\Imagick::setResourceLimit($resourceType, $limit))
				{
					throw new \RuntimeException('Could not configure an Imagick resource limit.');
				}
			}
		}
		catch (\Throwable $exception)
		{
			$this->restoreImagickResourceLimits($previousLimits);

			throw $exception;
		}

		return $previousLimits;
	}

	private function restoreImagickResourceLimits(array $previousLimits): void
	{
		foreach ($previousLimits as $resourceType => $previousLimit)
		{
			try
			{
				\Imagick::setResourceLimit((int)$resourceType, (int)$previousLimit);
			}
			catch (\Throwable)
			{
				continue;
			}
		}
	}

	private function hasTooManyPixels(\Imagick $image): bool
	{
		$width = $image->getImageWidth();
		$height = $image->getImageHeight();
		if ($width <= 0 || $height <= 0)
		{
			return false;
		}

		return $width > intdiv(self::MAX_IMAGE_PIXELS, $height);
	}

	private function isTimedOut(float $startedAt, ?\Throwable $exception = null): bool
	{
		if (microtime(true) - $startedAt >= self::MAX_CONVERSION_TIME)
		{
			return true;
		}

		if ($exception === null)
		{
			return false;
		}

		$message = mb_strtolower($exception->getMessage());

		return str_contains($message, 'time limit')
			|| str_contains($message, 'timeout')
			|| str_contains($message, 'timed out');
	}

	private function isValidPreviewToken(int $sourceFileId, int $previewImageId, string $previewToken): bool
	{
		try
		{
			$tokenValue = $this->signer->unsign($previewToken, self::PREVIEW_TOKEN_SALT);
		}
		catch (\Throwable)
		{
			return false;
		}

		return hash_equals($this->buildPreviewTokenValue($sourceFileId, $previewImageId), $tokenValue);
	}

	private function buildPreviewTokenValue(int $sourceFileId, int $previewImageId): string
	{
		return $sourceFileId . ':' . $previewImageId;
	}

	private function buildPreviewName(string $displayName): string
	{
		$name = (string)pathinfo($displayName, PATHINFO_FILENAME);

		return ($name !== '' ? $name : 'tiff-preview') . '.png';
	}

	private function buildActionUrl(string $action, array $params): string
	{
		return (string)Engine\UrlManager::getInstance()->create($action, $params);
	}

	private function addUrlParams(string $url, array $params): string
	{
		$uri = new Uri($url);
		$uri->addParams($params);

		return $uri->getUri();
	}

	private function getErrorMessage(string $errorCode): string
	{
		$messageCode = match ($errorCode)
		{
			ErrorCode::IMAGICK_UNAVAILABLE => 'DISK_TIFF_PREVIEW_ERROR_IMAGICK_UNAVAILABLE',
			ErrorCode::TIFF_UNSUPPORTED => 'DISK_TIFF_PREVIEW_ERROR_TIFF_UNSUPPORTED',
			ErrorCode::FILE_TOO_LARGE => 'DISK_TIFF_PREVIEW_ERROR_FILE_TOO_LARGE',
			ErrorCode::IMAGE_TOO_LARGE => 'DISK_TIFF_PREVIEW_ERROR_IMAGE_TOO_LARGE',
			ErrorCode::TIMEOUT => 'DISK_TIFF_PREVIEW_ERROR_TIMEOUT',
			default => 'DISK_TIFF_PREVIEW_ERROR_CONVERSION_FAILED',
		};

		return (string)Loc::getMessage($messageCode);
	}

	private function normalizeErrorCode(string $errorCode): string
	{
		return match ($errorCode)
		{
			ErrorCode::IMAGICK_UNAVAILABLE,
			ErrorCode::TIFF_UNSUPPORTED,
			ErrorCode::FILE_TOO_LARGE,
			ErrorCode::IMAGE_TOO_LARGE,
			ErrorCode::TIMEOUT,
			ErrorCode::CONVERSION_FAILED => $errorCode,
			default => ErrorCode::CONVERSION_FAILED,
		};
	}

	private function logEvent(string $event, array $context = [], string $level = 'info'): void
	{
		try
		{
			$this->logger->log($level, 'disk.tiff_preview.' . $event, array_merge(
				['event' => $event],
				$context,
			));
		}
		catch (\Throwable)
		{
			return;
		}
	}
}
