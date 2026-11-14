<?php

namespace Bitrix\Mobile\BitrixGpt\Voice;

use Bitrix\AI;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;

class AudioEngine
{
	public const CONTEXT = 'bitrixgpt_voice_transcribe';
	private const MAX_TRANSCRIPT_BYTES = 65535;

	public function __construct(
		private readonly string $publicHostUrl,
		private readonly string $region,
	)
	{
	}

	/**
	 * Checks access to the regional audio engine.
	 */
	public function isAvailable(int $userId): bool
	{
		if ($userId <= 0 || !Loader::includeModule('ai'))
		{
			return false;
		}

		$accessChecker = AI\Container::init()->getItem(AI\Services\CopilotAccessCheckerService::class);

		return $accessChecker->canAccessEngines($userId) && $this->getEngine($userId) !== null;
	}

	/**
	 * Selects the registered audio provider for the portal region.
	 */
	public static function getEngineCode(string $region): string
	{
		return in_array(strtolower($region), ['ru', 'by'], true) ? 'BitrixAudio' : 'Audio';
	}

	/**
	 * Starts standard AI queue processing or returns a direct recognition result.
	 *
	 * @param int $fileId Temporary upload file identifier.
	 * @return Result Data contains a queue hash or recognized text.
	 */
	public function start(int $userId, int $fileId): Result
	{
		$engine = $this->getEngine($userId);
		$file = \CFile::GetFileArray($fileId);
		$result = new Result();
		if ($engine === null || !$file)
		{
			return $result->addError(new Error('Audio recognition is unavailable.', 'AI_UNAVAILABLE'));
		}

		$engine
			->setHistoryState(false)
			->setCache(false)
			->setPayload((new AI\Payload\Audio($this->getFileUrl($file['SRC']), 'm4a'))->setMarkers(['type' => 'audio/mp4']))
			->onSuccess(function (AI\Result $response, ?string $queue = null) use ($result): void {
				$this->setResponse($result, $response, $queue);
			})
			->onError(static function (Error $error) use ($result): void {
				$result->addError($error);
			})
			->completionsInQueue()
		;

		if ($result->isSuccess() && $result->getData() === [])
		{
			$result->addError(new Error('Missing AI queue response.', 'INVALID_AI_RESPONSE'));
		}

		return $result;
	}

	private function setResponse(Result $result, AI\Result $response, ?string $queue): void
	{
		if ($queue !== null && $queue !== '')
		{
			$result->setData(['queue' => $queue, 'text' => null]);

			return;
		}

		$raw = $response->getRawData();
		$text = is_array($raw) ? ($raw['text'] ?? null) : null;
		if (!is_string($text) || strlen($text) > self::MAX_TRANSCRIPT_BYTES)
		{
			$result->addError(new Error('Invalid recognition result.', 'INVALID_AI_RESPONSE'));

			return;
		}

		$result->setData(['queue' => null, 'text' => trim($text)]);
	}

	protected function getFileUrl(string $source): string
	{
		$uri = new Uri($source);
		if (!$uri->getHost())
		{
			$uri = (new Uri($this->publicHostUrl))->setPath($source);
		}

		return $uri->getUri();
	}

	public function getEngine(int $userId): ?AI\Engine
	{
		if (!Loader::includeModule('ai'))
		{
			return null;
		}

		$context = new AI\Context('mobile', self::CONTEXT, $userId);
		$code = self::getEngineCode($this->region);

		return AI\Engine::getByCode($code, $context, AI\Engine::CATEGORIES['audio']);
	}
}
