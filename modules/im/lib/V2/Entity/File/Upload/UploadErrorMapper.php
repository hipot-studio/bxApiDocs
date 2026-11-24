<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Entity\File\Upload;

use Bitrix\Im\V2\Entity\File\FileError;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\UI\FileUploader\UploaderError;
use Psr\Log\LoggerInterface;

Loader::requireModule('ui');

/**
 * Turns a refusal of the ui circuit into a refusal of the chunked upload door.
 *
 * Not one code of {@see UploaderError} reaches the caller: the circuit is an implementation of the
 * door, its dictionary is not a contract, and some of its codes name a defect of ours rather than
 * anything the caller did. Whatever is not translated here leaves as FILE_UPLOAD_FAILED - the same
 * word the one-shot door of im already answers with - and its detail goes to the log instead.
 */
final class UploadErrorMapper
{
	/**
	 * The two codes im.v2.File.upload already answers with. The door keeps the same word for the same
	 * case, so a client that handles the one-shot upload needs no second branch here.
	 */
	public const FALLBACK_CODE = 'FILE_UPLOAD_FAILED';
	public const TOO_LARGE_CODE = 'FILE_TOO_LARGE';

	private const LOGGER_ID = 'im.chunkedUploadDoor';

	/**
	 * Values of php.ini the circuit puts into CLOUD_INVALID_CHUNK_SIZE. The door answers the window it
	 * computed itself and tells nobody how the portal is configured.
	 */
	private const PHP_INI_KEYS = ['postMaxSize', 'uploadMaxFileSize'];

	private ?LoggerInterface $logger;

	public function __construct(?LoggerInterface $logger = null)
	{
		$this->logger = $logger;
	}

	public function map(Error $error): Error
	{
		$code = (string)$error->getCode();
		$ourCode = $this->translate($code);

		if ($ourCode === null)
		{
			$this->logUntranslated($error);

			return new Error('File upload failed', self::FALLBACK_CODE);
		}

		if ($ourCode === self::TOO_LARGE_CODE)
		{
			return new Error('File size exceeds maximum allowed', self::TOO_LARGE_CODE);
		}

		return new FileError($ourCode, $this->cleanCustomData($error->getCustomData()));
	}

	private function translate(string $code): ?string
	{
		return match ($code)
		{
			UploaderError::INVALID_CHUNK_OFFSET => FileError::UPLOAD_CHUNK_OFFSET_MISMATCH,
			UploaderError::CHUNK_TOO_BIG,
			UploaderError::INVALID_CHUNK_SIZE,
			UploaderError::INVALID_RANGE_SIZE => FileError::UPLOAD_CHUNK_TOO_BIG,
			UploaderError::CHUNK_NOT_FOUND => FileError::UPLOAD_CHUNK_MISSING,
			UploaderError::CLOUD_INVALID_CHUNK_SIZE => FileError::UPLOAD_CHUNK_WINDOW_EMPTY,
			UploaderError::FILE_UPLOAD_ACCESS_DENIED => FileError::ACCESS_DENIED,
			UploaderError::MAX_FILE_SIZE_EXCEEDED => self::TOO_LARGE_CODE,
			default => null,
		};
	}

	private function cleanCustomData(array $customData): array
	{
		foreach (self::PHP_INI_KEYS as $key)
		{
			unset($customData[$key]);
		}

		return $customData;
	}

	private function logUntranslated(Error $error): void
	{
		// Only the code and the message of the circuit: both are its own constants, and neither the
		// upload id, nor the file name, nor the address of the caller belongs in a log of this door.
		$this->getLogger()?->error(
			'chunked upload door: untranslated refusal {code} of the ui circuit: {message}',
			['code' => (string)$error->getCode(), 'message' => $error->getMessage()],
		);
	}

	private function getLogger(): ?LoggerInterface
	{
		return $this->logger ??= (new LoggerFactory())->createById(self::LOGGER_ID);
	}
}
