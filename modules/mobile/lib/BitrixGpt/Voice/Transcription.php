<?php

namespace Bitrix\Mobile\BitrixGpt\Voice;

use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\UI\FileUploader\TempFileTable;
use Bitrix\UI\FileUploader\Uploader;

final class Transcription
{
	private const MAX_UPLOAD_TOKEN_LENGTH = 512;

	public function __construct(private readonly AudioEngine $engine)
	{
	}

	/**
	 * Uploads audio and starts recognition using the standard AI queue.
	 *
	 * @param int $userId Authenticated upload owner.
	 * @param string $path Server path to an uploaded M4A file.
	 * @return Result Data contains queue, text and uploadToken.
	 */
	public function transcribe(int $userId, string $path): Result
	{
		$unavailable = $this->checkPreconditions($userId);
		if ($unavailable !== null)
		{
			return $unavailable;
		}

		$inspection = AudioUpload::inspect($path);
		if (!$inspection->isSuccess())
		{
			return $inspection;
		}

		$upload = AudioUpload::forUser($userId)->store($path);
		if (!$upload->isSuccess())
		{
			return $upload;
		}

		return $this->startRecognition($userId, $upload->getData()['fileId'], $upload->getData()['uploadToken']);
	}

	/**
	 * Starts recognition of audio already uploaded through the UI FileUploader protocol.
	 *
	 * @param int $userId Authenticated upload owner.
	 * @param string $uploadToken Token of a finished upload signed for this user.
	 * @return Result Data contains queue, text and the same uploadToken.
	 */
	public function transcribeUploaded(int $userId, string $uploadToken): Result
	{
		$unavailable = $this->checkPreconditions($userId);
		if ($unavailable !== null)
		{
			return $unavailable;
		}

		$resolved = $this->resolveUploadedFile($userId, $uploadToken);
		if (!$resolved->isSuccess())
		{
			return $resolved;
		}

		$fileId = $resolved->getData()['fileId'];
		$inspection = AudioUpload::inspectStored($fileId);
		if (!$inspection->isSuccess())
		{
			return $inspection;
		}

		return $this->startRecognition($userId, $fileId, $uploadToken);
	}

	private function checkPreconditions(int $userId): ?Result
	{
		if ($userId <= 0 || !Loader::includeModule('ui') || !$this->engine->isAvailable($userId))
		{
			return $this->error('AI_UNAVAILABLE');
		}

		return null;
	}

	/**
	 * Every way a token can be unusable — forged, unknown, unfinished, removed, expired,
	 * signed for another user or controller, longer than the platform limit — deliberately
	 * maps to one public code, so the client cannot tell forgery from expiry.
	 */
	private function resolveUploadedFile(int $userId, string $uploadToken): Result
	{
		if (strlen($uploadToken) > self::MAX_UPLOAD_TOKEN_LENGTH)
		{
			return $this->error('INVALID_UPLOAD_TOKEN');
		}

		$pendingFile = (new Uploader(AudioUpload::forUser($userId)))
			->getPendingFiles([$uploadToken])
			->get($uploadToken)
		;

		$fileId = $pendingFile?->getFileId();
		$guid = $pendingFile?->getGuid();
		$tempFile = ($fileId !== null && $guid !== null)
			? TempFileTable::getList(['filter' => ['=GUID' => $guid]])->fetchObject()
			: null
		;

		if ($fileId === null || $tempFile === null || $tempFile->getCreatedBy() !== $userId)
		{
			return $this->error('INVALID_UPLOAD_TOKEN');
		}

		return (new Result())->setData(['fileId' => $fileId]);
	}

	private function startRecognition(int $userId, int $fileId, string $uploadToken): Result
	{
		try
		{
			$result = $this->engine->start($userId, $fileId);
		}
		catch (\Throwable)
		{
			// The queue may have accepted the request. Its upload remains under the UI uploader TTL.
			return $this->error('AI_ERROR');
		}

		if (!$result->isSuccess())
		{
			return $this->error($this->publicErrorCode($result->getErrors()[0]));
		}

		return (new Result())->setData($result->getData() + ['uploadToken' => $uploadToken]);
	}

	private function publicErrorCode(Error $error): string
	{
		$code = (string)$error->getCode();
		if (in_array($code, [
			'AI_UNAVAILABLE',
			'INVALID_AI_RESPONSE',
			'MUST_AGREE_WITH_AGREEMENT',
			'LIMIT_IS_EXCEEDED',
			'LIMIT_IS_EXCEEDED_DAILY',
			'LIMIT_IS_EXCEEDED_MONTHLY',
			'LIMIT_IS_EXCEEDED_BAAS',
			'LIMIT_IS_EXCEEDED_BAAS_RATE_LIMIT',
		], true))
		{
			return $code;
		}

		return 'AI_ERROR';
	}

	private function error(string $code): Result
	{
		return (new Result())->addError(new Error('Voice transcription request failed.', $code));
	}
}
