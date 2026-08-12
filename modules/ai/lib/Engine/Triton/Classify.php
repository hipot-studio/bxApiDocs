<?php

declare(strict_types=1);

namespace Bitrix\AI\Engine\Triton;

use Bitrix\AI\Config;
use Bitrix\AI\Engine\Engine;
use Bitrix\AI\Engine\IEngine;
use Bitrix\AI\Engine\IQueue;
use Bitrix\AI\Facade\Bitrix24;
use Bitrix\AI\Quality;
use Bitrix\AI\QueueJob;
use Bitrix\AI\Result;

class Classify extends Engine implements IEngine, IQueue
{
	protected const CATEGORY_CODE = 'classify';
	protected const ENGINE_CODE   = 'TritonClassify';
	protected const ENGINE_NAME   = 'Triton Classify';

	private const COMPLETIONS_URL_PATH = '/api/v1/classify';

	public function isAvailable(): bool
	{
		if (Bitrix24::shouldUseB24())
		{
			return false;
		}

		return !empty(Config::getValue('triton_http_url'))
			&& !empty(Config::getValue('queue_url'));
	}

	public function inTariff(): bool
	{
		return true;
	}

	protected function getCompletionsUrl(): string
	{
		return rtrim((string)(Config::getValue('triton_http_url') ?? ''), '/') . self::COMPLETIONS_URL_PATH;
	}

	protected function getAuthorizationHeader(): string
	{
		$tritonAuth = (string)(Config::getValue('triton_authorization') ?? '');
		if ($tritonAuth === '')
		{
			return '';
		}

		return str_starts_with($tritonAuth, 'Bearer ') ? $tritonAuth : 'Bearer ' . $tritonAuth;
	}

	protected function makeRequestParams(array $postParams = []): array
	{
		return ['text' => $this->getPayload()->getData()];
	}

	public function completions(): void
	{
		$this->completionsInQueue();
	}

	public function getQueueJob(): QueueJob
	{
		if (!$this->queueJob)
		{
			$this->queueJob = QueueJob::createWithinFromEngine($this);
		}

		return $this->queueJob;
	}

	public function hasQuality(Quality $quality): bool
	{
		return array_diff($quality->getRequired(), ['json_response_mode']) === [];
	}

	/**
	 * @param mixed $rawResult Expected: {"label": "urgent"|"risky"|"lost", "probs": {...}}
	 * @param bool $cached
	 * @return Result
	 */
	public function getResultFromRaw(mixed $rawResult, bool $cached = false): Result
	{
		$label = is_array($rawResult) && is_string($rawResult['label'] ?? null)
			? $rawResult['label']
			: null;
		$jsonData = is_array($rawResult) ? $rawResult : null;

		return new Result($rawResult, $label, $cached, $jsonData);
	}
}
