<?php

namespace Bitrix\Mobile\BitrixGpt\Voice;

use Bitrix\Main\Error;
use Bitrix\Main\IO\File;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\UI\FileUploader;

final class AudioUpload extends FileUploader\UploaderController implements FileUploader\Contracts\CustomFingerprint
{
	public const MAX_BYTES = 10 * 1024 * 1024;

	public function __construct(private readonly int $userId)
	{
		parent::__construct([]);
	}

	/**
	 * Validates the size and container signature of an M4A upload.
	 */
	public static function inspect(string $path): Result
	{
		$result = new Result();
		$size = is_file($path) ? filesize($path) : 0;
		if ($size < 16 || $size > self::MAX_BYTES)
		{
			return $result->addError(new Error('Invalid audio size.', 'INVALID_AUDIO'));
		}

		$header = file_get_contents($path, false, null, 0, 16);
		if (
			$header === false
			|| substr($header, 4, 4) !== 'ftyp'
			|| !in_array(substr($header, 8, 4), ['M4A ', 'isom', 'mp42'], true)
		)
		{
			return $result->addError(new Error('Expected an M4A recording.', 'INVALID_AUDIO'));
		}

		return $result;
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
		// Uploads are accepted only by BitrixGptVoice after validating the fragment.
		return false;
	}

	public function canUpload(): bool
	{
		return $this->userId > 0;
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
