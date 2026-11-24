<?php

namespace Bitrix\Mobile\BitrixGpt\Voice;

use Bitrix\Main\Config\Feature;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\IO\File;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Mobile\Public\Features\BitrixGptVoiceFeature;
use Bitrix\UI\FileUploader;

final class AudioUpload extends FileUploader\UploaderController implements FileUploader\Contracts\CustomFingerprint
{
	public const MAX_BYTES = 10 * 1024 * 1024;

	private const MIN_CHUNK_SIZE = 1024 * 1024;

	private const HEADER_BYTES = 16;

	private const HEADER_READ_LIMIT = 64 * 1024;

	private const HEADER_READ_TIMEOUT = 10;

	private int $userId;

	/**
	 * Resolver options are ignored: the signature stays constant regardless of them, so
	 * tokens from any options are interchangeable. The user always comes from the session.
	 */
	public function __construct(array $options = [])
	{
		parent::__construct([]);
		$this->userId = (int)CurrentUser::get()->getId();
	}

	/**
	 * Creates a controller for a server-side call with a known user, bypassing CurrentUser.
	 */
	public static function forUser(int $userId): self
	{
		$controller = new self();
		$controller->userId = $userId;

		return $controller;
	}

	/**
	 * The chunk size a client should use for sequential upload: at least 1 MiB and the
	 * cloud storage minimum part size, capped by the platform's chunk size bounds.
	 */
	public static function getChunkSize(): int
	{
		$cloudMinPart = 0;
		if (Loader::includeModule('clouds'))
		{
			$bucket = \CCloudStorage::findBucketForFile(
				['FILE_SIZE' => self::MAX_BYTES, 'MODULE_ID' => 'mobile'],
				'recording.m4a',
			);
			if ($bucket && $bucket->init())
			{
				$cloudMinPart = $bucket->getService()->getMinUploadPartSize();
			}
		}

		return min(
			max(self::MIN_CHUNK_SIZE, FileUploader\Configuration::getChunkMinSize(), $cloudMinPart),
			FileUploader\Configuration::getChunkMaxSize(),
		);
	}

	/**
	 * Validates the size and container signature of an M4A file lying on a local path.
	 */
	public static function inspect(string $path): Result
	{
		$size = is_file($path) ? (int)filesize($path) : 0;
		$header = $size > 0 ? file_get_contents($path, false, null, 0, self::HEADER_BYTES) : false;

		return self::check($size, is_string($header) ? $header : '');
	}

	/**
	 * Validates an upload already committed to storage. The body is never fetched: a cloud
	 * recording would otherwise travel back in full, which is the transfer this chunked
	 * upload exists to avoid. Storage failures are reported apart from invalid audio.
	 */
	public static function inspectStored(int $fileId): Result
	{
		$file = \CFile::getFileArray($fileId);
		$header = is_array($file) ? self::readStoredHeader($file) : null;
		if ($header === null)
		{
			return (new Result())->addError(new Error('Cannot read stored audio.', 'STORAGE_UNAVAILABLE'));
		}

		return self::check((int)($file['FILE_SIZE'] ?? 0), $header);
	}

	private static function check(int $size, string $header): Result
	{
		$result = new Result();
		if ($size < self::HEADER_BYTES || $size > self::MAX_BYTES)
		{
			return $result->addError(new Error('Invalid audio size.', 'INVALID_AUDIO'));
		}

		if (
			strlen($header) < self::HEADER_BYTES
			|| substr($header, 4, 4) !== 'ftyp'
			|| !in_array(substr($header, 8, 4), ['M4A ', 'isom', 'mp42'], true)
		)
		{
			return $result->addError(new Error('Expected an M4A recording.', 'INVALID_AUDIO'));
		}

		return $result;
	}

	private static function readStoredHeader(array $file): ?string
	{
		if ((int)($file['HANDLER_ID'] ?? 0) > 0)
		{
			return self::readRemoteHeader((string)($file['SRC'] ?? ''));
		}

		// Without a storage handler MakeFileArray only composes the upload path; it copies nothing.
		$localFile = \CFile::makeFileArray((int)($file['ID'] ?? 0));
		$path = is_array($localFile) ? ($localFile['tmp_name'] ?? null) : null;
		if (!is_string($path) || !is_file($path))
		{
			return null;
		}

		$header = file_get_contents($path, false, null, 0, self::HEADER_BYTES);

		return is_string($header) ? $header : null;
	}

	/**
	 * Reads the container header of a cloud object with a ranged request. The body cap keeps
	 * a storage that ignores Range from streaming the whole recording back.
	 */
	private static function readRemoteHeader(string $url): ?string
	{
		if (!preg_match('#^https?://#i', $url))
		{
			return null;
		}

		$httpClient = new HttpClient([
			'socketTimeout' => self::HEADER_READ_TIMEOUT,
			'streamTimeout' => self::HEADER_READ_TIMEOUT,
		]);
		$httpClient->setHeader('Range', 'bytes=0-' . (self::HEADER_BYTES - 1));
		$httpClient->setBodyLengthMax(self::HEADER_READ_LIMIT);

		$header = $httpClient->get($url);

		return (is_string($header) && in_array($httpClient->getStatus(), [200, 206], true)) ? $header : null;
	}

	/**
	 * Stores temporary audio using the standard UI uploader retention policy.
	 *
	 * @return Result File identifier and a user-bound upload token.
	 */
	public function store(string $path): Result
	{
		$chunk = new FileUploader\Chunk(new File($path));
		$chunk->setName('recording.m4a');
		$chunk->setType('audio/mp4');
		$upload = (new FileUploader\Uploader($this))->upload($chunk);
		if (!$upload->isSuccess())
		{
			return (new Result())->addError(new Error('Cannot store audio.', 'UPLOAD_FAILED'));
		}

		$tempFile = $upload->getTempFile();

		return (new Result())->setData([
			'fileId' => $tempFile->getFileId(),
			'uploadToken' => $upload->getToken(),
		]);
	}

	/**
	 * Removes only an upload token signed for this user and controller.
	 *
	 * @return Result Removal confirmation or token validation errors.
	 */
	public function remove(string $uploadToken): Result
	{
		$result = new Result();
		if ($this->userId <= 0 || !str_contains($uploadToken, '.') || strlen($uploadToken) > 512)
		{
			return $result->addError(new Error('Invalid audio upload token.', 'INVALID_UPLOAD_TOKEN'));
		}

		$uploader = new FileUploader\Uploader($this);
		try
		{
			$removals = $uploader->remove([$uploadToken]);
		}
		catch (BadSignatureException)
		{
			return $result->addError(new Error('Invalid audio upload token.', 'INVALID_UPLOAD_TOKEN'));
		}

		foreach ($removals as $removal)
		{
			if (!$removal->isSuccess())
			{
				return $result->addError(new Error('Invalid audio upload token.', 'INVALID_UPLOAD_TOKEN'));
			}
		}

		$status = $uploader->getStatus($uploadToken);
		if ($status->isSuccess() || $status->getErrors()[0]->getCode() !== FileUploader\UploaderError::UNKNOWN_TOKEN)
		{
			return $result->addError(new Error('Cannot remove audio upload.', 'UPLOAD_REMOVE_FAILED'));
		}

		return $result->setData(['removed' => true]);
	}

	/**
	 * Binds upload signatures to the authenticated user across HTTP requests.
	 */
	public function getFingerprint(): string
	{
		return 'mobile-voice-user-' . $this->userId;
	}

	public function isAvailable(): bool
	{
		return $this->userId > 0 && Feature::isEnabled(BitrixGptVoiceFeature::class);
	}

	/**
	 * The full per-user voice gate: AI engine, CoPilot chat, agreement, plan and readiness.
	 * Uploader asks for it once, on the first chunk of an upload, so every following chunk
	 * still costs only the cheap isAvailable() check.
	 */
	public function canUpload(): bool
	{
		return $this->userId > 0 && $this->isVoiceAvailableForUser();
	}

	private function isVoiceAvailableForUser(): bool
	{
		$serviceLocator = ServiceLocator::getInstance();
		if (!$serviceLocator->has(Availability::class))
		{
			return false;
		}

		try
		{
			return $serviceLocator->get(Availability::class)->isAvailable($this->userId);
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public function getConfiguration(): FileUploader\Configuration
	{
		return new FileUploader\Configuration([
			'acceptedFileTypes' => ['.m4a'],
			'maxFileSize' => self::MAX_BYTES,
		]);
	}

	public function getCommitOptions(): FileUploader\CommitOptions
	{
		return new FileUploader\CommitOptions([
			'moduleId' => 'mobile',
			'savePath' => 'mobile/bitrixgpt/voice',
		]);
	}

	public function canView(): bool
	{
		return false;
	}

	public function canRemove(): bool
	{
		return false;
	}

	public function verifyFileOwner(FileUploader\FileOwnershipCollection $files): void
	{
	}
}
