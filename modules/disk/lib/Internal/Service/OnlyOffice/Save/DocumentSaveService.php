<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Save;

use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\File;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\IO;
use Bitrix\Main\Result;
use Bitrix\Main\Web\MimeType;
use Psr\Log\LoggerInterface;

/**
 * Turns the save callback of the editor server into a version of the document: download, verify, write.
 *
 * The outcome is the answer to the callback, so every way the save can end has to be told apart and
 * named - a save which never reached Disk must not be confirmed to the editor server. What went wrong
 * is only visible here, and the editing is already over by then, so every failure leaves a record in
 * the log: the category, the editor server and the durations of the steps, never the callback itself,
 * its url with the tokens or the hash of the session.
 */
final class DocumentSaveService
{
	public const DATA_WRITTEN = 'written';
	public const DATA_FAILURE_CATEGORY = 'failureCategory';
	public const DATA_SKIPPED_CONTAINER_CHECK = 'skippedContainerCheck';

	public const LOGGER_ID = 'disk.onlyoffice.save';

	/**
	 * The log of the save path is built in one place - the services of the module - and taken from there
	 * by everyone who writes into it. Built here, every caller would have to repeat which destination a
	 * portal without a channel of its own falls back to.
	 */
	public const LOGGER_SERVICE_ID = 'disk.onlyoffice.saveLogger';

	/** A save slower than this is worth a record of its own: the limits of the download are tunable. */
	private const SLOW_SAVE_MS = 10000;

	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly EditedDocumentDownloaderInterface $downloader,
		private readonly DownloadedFileVerifier $verifier,
		?LoggerInterface $logger = null,
	)
	{
		$this->logger = $logger ?? ServiceLocator::getInstance()->get(self::LOGGER_SERVICE_ID);
	}

	/**
	 * @param array $callbackData body of the save callback: `status`, `url`, `filetype`, `users`.
	 * @param float $processingStartedAt `microtime(true)` of the start of the processing of the webhook.
	 * @param \Closure|null $beforeWrite called once the document is downloaded and verified, right before
	 *        the version is written. The caller marks the key of a final save there, so the lifetime of
	 *        that mark never has to cover a download and a write at once.
	 * @param array $writeOptions options the write node is told the version with, on top of the ones of
	 *        every save here; the caller names the kind of the write and when its session began.
	 * @return Result data keys DATA_*; a failure carries the error code of the category it got, and its
	 *         data tells the caller the same category - the answer to the callback is built from it.
	 */
	public function save(
		DocumentSession $documentSession,
		array $callbackData,
		float $processingStartedAt,
		?\Closure $beforeWrite = null,
		array $writeOptions = [],
	): Result
	{
		$context = $this->makeContext($documentSession, $callbackData);
		$tmpFile = null;

		try
		{
			$url = trim((string)($callbackData['url'] ?? ''));
			if ($url === '')
			{
				return $this->fail(
					SaveFailureCategory::DownloadFailed,
					'The save callback carries no url.',
					$context,
					$processingStartedAt,
				);
			}

			$object = $documentSession->getObject();
			if (!$object)
			{
				return $this->fail(
					SaveFailureCategory::WriteFailed,
					'Could not find the file of the document session.',
					$context,
					$processingStartedAt,
				);
			}

			$downloadResult = $this->downloader->downloadToTempFile($url, $processingStartedAt);
			$downloadData = $downloadResult->getData();
			$expectedSize = $downloadData[EditedDocumentDownloaderInterface::DATA_CONTENT_LENGTH] ?? null;
			$context['docServerHost'] = $downloadData[EditedDocumentDownloaderInterface::DATA_DOC_SERVER_HOST] ?? null;
			$context['httpStatus'] = $downloadData[EditedDocumentDownloaderInterface::DATA_HTTP_STATUS] ?? null;
			$context['attempts'] = $downloadData[EditedDocumentDownloaderInterface::DATA_ATTEMPTS] ?? null;
			$context['downloadMs'] = $downloadData[EditedDocumentDownloaderInterface::DATA_DOWNLOAD_MS] ?? null;
			$context['expectedSize'] = $expectedSize;

			if (!$downloadResult->isSuccess())
			{
				return $this->fail(
					SaveFailureCategory::DownloadFailed,
					$this->describeErrors($downloadResult->getErrors()) ?: 'Could not download the edited document.',
					$context,
					$processingStartedAt,
				);
			}

			$tmpFile = (string)$downloadData[EditedDocumentDownloaderInterface::DATA_FILE];
			$context['actualSize'] = $this->readSize($tmpFile);

			$verifyResult = $this->verifier->verify(
				$tmpFile,
				$expectedSize,
				$this->readFileType($callbackData),
				(string)$object->getExtension(),
			);
			$skippedContainerCheck = (bool)(
				$verifyResult->getData()[DownloadedFileVerifier::DATA_SKIPPED_CONTAINER_CHECK] ?? false
			);

			if (!$verifyResult->isSuccess())
			{
				return $this->fail(
					SaveFailureCategory::IncompleteFile,
					$this->describeErrors($verifyResult->getErrors()) ?: 'The downloaded document is incomplete.',
					$context,
					$processingStartedAt,
					$skippedContainerCheck,
				);
			}

			if ($skippedContainerCheck)
			{
				$this->logger->notice(
					'OnlyOffice save: the container of the document was left unchecked, no zip extension.',
					array_merge($context, ['totalMs' => $this->elapsedMs($processingStartedAt)]),
				);
			}

			if ($beforeWrite !== null)
			{
				$beforeWrite();
			}

			$writeStartedAt = microtime(true);
			$version = $object->uploadVersion(
				$this->makeFileArray($tmpFile, $callbackData, $object),
				$this->resolveAuthorId($callbackData, $documentSession),
				array_merge(['commentAttachedObjects' => false], $writeOptions),
			);
			$context['writeMs'] = $this->elapsedMs($writeStartedAt);

			if (!$version)
			{
				$context['uploadVersionErrors'] = $this->describeErrors($object->getErrors()) ?: null;

				return $this->fail(
					SaveFailureCategory::WriteFailed,
					'Could not write the version of the document.',
					$context,
					$processingStartedAt,
					$skippedContainerCheck,
				);
			}

			return $this->succeed($context, $processingStartedAt, $skippedContainerCheck);
		}
		catch (\Throwable $exception)
		{
			return $this->fail(
				SaveFailureCategory::WriteFailed,
				$exception::class . ': ' . $exception->getMessage(),
				$context,
				$processingStartedAt,
			);
		}
		finally
		{
			if ($tmpFile !== null)
			{
				$this->removeTempFile($tmpFile);
			}
		}
	}

	private function makeContext(DocumentSession $documentSession, array $callbackData): array
	{
		return [
			'sessionId' => (int)$documentSession->getId(),
			'objectId' => $documentSession->getObjectId(),
			'callbackStatus' => isset($callbackData['status']) ? (int)$callbackData['status'] : null,
			'category' => null,
			'docServerHost' => null,
			'httpStatus' => null,
			'attempts' => null,
			'expectedSize' => null,
			'actualSize' => null,
			'uploadVersionErrors' => null,
			'downloadMs' => null,
			'writeMs' => null,
			'totalMs' => null,
		];
	}

	private function succeed(array $context, float $processingStartedAt, bool $skippedContainerCheck): Result
	{
		$context['totalMs'] = $this->elapsedMs($processingStartedAt);
		if ($context['totalMs'] > self::SLOW_SAVE_MS)
		{
			$this->logger->info('OnlyOffice save: the document was written in {totalMs} ms.', $context);
		}

		return (new Result())->setData([
			self::DATA_WRITTEN => true,
			self::DATA_FAILURE_CATEGORY => null,
			self::DATA_SKIPPED_CONTAINER_CHECK => $skippedContainerCheck,
		]);
	}

	private function fail(
		SaveFailureCategory $category,
		string $message,
		array $context,
		float $processingStartedAt,
		bool $skippedContainerCheck = false,
	): Result
	{
		$context['category'] = $category->value;
		$context['totalMs'] = $this->elapsedMs($processingStartedAt);
		$context['message'] = $message;
		$this->logger->warning('OnlyOffice save failed: {category}. {message}', $context);

		return (new Result())
			->addError(new Error($message, $category->value))
			->setData([
				self::DATA_WRITTEN => false,
				self::DATA_FAILURE_CATEGORY => $category,
				self::DATA_SKIPPED_CONTAINER_CHECK => $skippedContainerCheck,
			])
		;
	}

	/**
	 * The editor server sends an office document as a generic archive, and the type of the temporary file
	 * is read from its contents, so the version would keep that generic type instead of its own.
	 */
	private function makeFileArray(string $tmpFile, array $callbackData, File $object): array
	{
		$fileArray = \CFile::makeFileArray($tmpFile);
		if (in_array($fileArray['type'] ?? '', ['application/encrypted', 'application/zip'], true))
		{
			$fileType = $this->readFileType($callbackData) ?? (string)$object->getExtension();
			$fileArray['type'] = MimeType::getByFileExtension($fileType);
		}

		return $fileArray;
	}

	private function readFileType(array $callbackData): ?string
	{
		$fileType = $callbackData['filetype'] ?? null;

		return is_scalar($fileType) ? (string)$fileType : null;
	}

	/**
	 * The author of the version is the first participant the callback names; a callback which names none
	 * leaves the owner of the session.
	 */
	private function resolveAuthorId(array $callbackData, DocumentSession $documentSession): int
	{
		$users = $callbackData['users'] ?? null;
		if ($users !== null && !is_array($users))
		{
			$users = [$users];
		}

		return (int)($users[0] ?? $documentSession->getUserId());
	}

	/**
	 * @param Error[] $errors
	 */
	private function describeErrors(array $errors): string
	{
		$described = [];
		foreach ($errors as $error)
		{
			$code = (string)$error->getCode();
			$described[] = $code === '' ? $error->getMessage() : "{$code}: {$error->getMessage()}";
		}

		return implode('; ', $described);
	}

	private function readSize(string $filePath): ?int
	{
		$file = new IO\File($filePath);

		return $file->isExists() ? (int)$file->getSize() : null;
	}

	private function removeTempFile(string $filePath): void
	{
		$file = new IO\File($filePath);
		if ($file->isExists())
		{
			$file->delete();
		}
	}

	private function elapsedMs(float $startedAt): int
	{
		return (int)round((microtime(true) - $startedAt) * 1000);
	}
}
