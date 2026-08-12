<?php
namespace Bitrix\Landing\AI\SiteBuilder\Service;

use Bitrix\Landing\AI\SiteBuilder\Font\AiFontPolicy;
use Bitrix\Landing\Copilot\Connector\AI\Prompt as CopilotPrompt;
use Bitrix\Landing\Copilot\Connector\AI\Text as CopilotText;
use Bitrix\Landing\Copilot\Connector\Schema\Schema;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

/**
 * Base class for SiteBuilder services with shared prompt and AI-request helpers.
 */
abstract class AbstractService
{
	protected function createSuccess(array $data = []): Result
	{
		$result = new Result();
		if ($data)
		{
			$result->setData($data);
		}

		return $result;
	}

	protected function createSuccessWithResult($payload, array $meta = []): Result
	{
		$data = [
			'result' => $payload,
		];
		if ($meta)
		{
			$data['meta'] = $meta;
		}

		return $this->createSuccess($data);
	}

	protected function createSuccessWithHash(string $hash, array $meta = []): Result
	{
		$data = [
			'hash' => $hash,
		];
		if ($meta)
		{
			$data['meta'] = $meta;
		}

		return $this->createSuccess($data);
	}

	protected function createError(string $message, string $code, array $context = [], array $meta = []): Result
	{
		$result = new Result();
		$result->addError(new Error($message, $code));

		$data = [];
		if ($context)
		{
			$data['context'] = $context;
		}
		if ($meta)
		{
			$data['meta'] = $meta;
		}
		if ($data)
		{
			$result->setData($data);
		}

		return $result;
	}

	private function preparePromptReplacements(array $replacements): array
	{
		$replacements['{{ai_font_policy}}'] = AiFontPolicy::getPromptInstructions();
		$replacements['{{current_date}}'] = date('d.m.Y');

		return $replacements;
	}

	protected function requestPromptByCode(
		string $promptCode,
		array $markers,
		array $meta = [],
		?Schema $schema = null,
		bool $forceZeroCost = false,
	): Result
	{
		$prompt = new CopilotPrompt($promptCode);
		$prompt->setMarkers($this->preparePromptReplacements($markers));
		if ($forceZeroCost)
		{
			$prompt->setCost(0);
		}
		if ($schema !== null)
		{
			$prompt->setSchema($schema);
		}

		$result = $this->requestCopilotPrompt($prompt);
		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();

			return $this->createError(
				$errors ? $errors[0]->getMessage() : 'Ошибка запроса к AI.',
				'AI_REQUEST_FAILED',
				[
					'reason' => 'ai_request_failed',
					'errors' => $errors,
				],
				$meta
			);
		}

		$data = $result->getData();
		if (isset($data['result']) && $data['result'] !== '')
		{
			$payload = is_string($data['result']) ? $data['result'] : Json::encode($data['result']);
			$payload = $this->normalizePromptPayload($payload);

			return $this->createSuccessWithResult($payload, $meta);
		}

		if (isset($data['hash']) && $data['hash'] !== '')
		{
			return $this->createSuccessWithHash((string)$data['hash'], $meta);
		}

		return $this->createError('Пустой ответ от AI.', 'EMPTY_RESULT', [
			'reason' => 'empty_result',
		], $meta);
	}

	protected function requestCopilotPrompt(CopilotPrompt $prompt): Result
	{
		return (new CopilotText())->request($prompt);
	}

	protected function normalizePromptPayload(mixed $payload): mixed
	{
		if (is_array($payload) && isset($payload['html']) && is_string($payload['html']))
		{
			return $payload['html'];
		}

		if (!is_string($payload))
		{
			return $payload;
		}

		$trimmedPayload = trim($payload);
		if ($trimmedPayload === '' || $trimmedPayload[0] !== '{')
		{
			return $payload;
		}

		try
		{
			$decodedPayload = Json::decode($trimmedPayload);
		}
		catch (\Throwable)
		{
			return $payload;
		}

		if (
			is_array($decodedPayload)
			&& isset($decodedPayload['html'])
			&& is_string($decodedPayload['html'])
			&& trim($decodedPayload['html']) !== ''
		)
		{
			return $decodedPayload['html'];
		}

		return $payload;
	}

	protected function extractMeta(array $data): array
	{
		return isset($data['meta']) && is_array($data['meta'])
			? $data['meta']
			: [];
	}

}
