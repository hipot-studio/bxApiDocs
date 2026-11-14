<?php

namespace Bitrix\Mobile\BitrixGpt\Voice;

use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

final class Transcription
{
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
		if ($userId <= 0 || !Loader::includeModule('ui') || !$this->engine->isAvailable($userId))
		{
			return $this->error('AI_UNAVAILABLE');
		}

		$inspection = AudioUpload::inspect($path);
		if (!$inspection->isSuccess())
		{
			return $inspection;
		}

		$upload = (new AudioUpload($userId))->store($path);
		if (!$upload->isSuccess())
		{
			return $upload;
		}

		try
		{
			$result = $this->engine->start($userId, $upload->getData()['fileId']);
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

		return (new Result())->setData($result->getData() + ['uploadToken' => $upload->getData()['uploadToken']]);
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
