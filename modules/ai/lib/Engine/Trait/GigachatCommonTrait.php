<?php
namespace Bitrix\AI\Engine\Trait;

use Bitrix\AI\Context;
use Bitrix\AI\Engine\Cloud\CloudEngine;
use Bitrix\AI\Engine\Models\GigaChatModel;
use Bitrix\AI\Facade\Bitrix24;
use Bitrix\AI\Result;
use Bitrix\Main\Application;

trait GigachatCommonTrait
{
	/**
	 * @inheritDoc
	 */
	protected function getCompletionsUrl(): string
	{
		return self::URL_COMPLETIONS;
	}

	/**
	 * @inheritDoc
	 */
	public function isAvailable(): bool
	{
		if (Bitrix24::shouldUseB24())
		{
			$zone = Bitrix24::getPortalZone();

			return $zone === 'ru' || $zone === 'by';
		}

		$region = Application::getInstance()->getLicense()->getRegion();

		return $region === 'ru' || $region === 'by';
	}

	/**
	 * @inheritDoc
	 */
	public function setUserParameters(array $params): void
	{
		$toSet = [];

		if (isset($params['temperature']))
		{
			$toSet['temperature'] = (float)$params['temperature'];
		}

		if ($params['model'] ?? null)
		{
			$toSet['model'] = (string)$params['model'];
		}

		$this->setParameters($toSet);
	}

	/**
	 * @inheritDoc
	 */
	protected function getSystemParameters(): array
	{
		return [
			'model' => $this->getModel()->value,
			'temperature' => self::TEMPERATURE,
			'n' => self::VARIANTS,
		];
	}

	public function getMaxOutputTokens(): int
	{
		return self::DEFAULT_MAX_OUTPUT_TOKENS;
	}

	/**
	 * Returns message's length in tokens.
	 *
	 * @link https://developers.sber.ru/docs/ru/gigachat/limitations
	 * @link https://developers.sber.ru/docs/ru/gigachat/api/reference/rest/post-tokens-count
	 * @param Context\Message $message Message item.
	 * @return int
	 */
	protected function getMessageLength(Context\Message $message): int
	{
		return mb_strlen($message->getContent()) / 2.7;
	}

	public function getContextLimit(): int
	{
		// Previosly it was 1700 symbols and used \Bitrix\AI\Engine\Engine::$modelContextLimit.
		// Now it's value from GigaChat documentation, but we can setup model by Config for each request
		// or for one request. So, in that cases we should refactor code to get real limit from model from payload.
		$model = ($this instanceof CloudEngine) ? GigaChatModel::from($this->getModel()) : $this->getModel();

		return $model->contextLimit() - $this->getMaxOutputTokens();
	}

	/**
	 * Builds and returns messages for completions.
	 *
	 * @return array
	 */
	private function getPreparedMessages(): array
	{
		$data = [];

		// system role (instruction)
		if ($role = $this->payload->getRole())
		{
			$data[] = [
				'role' => self::SYSTEM_ROLE,
				'content' => $role->getInstruction(),
			];
		}

		// context messages
		if ($this->params['collect_context'] ?? false)
		{
			foreach ($this->getMessages() as $message)
			{
				$data[] = [
					'role' => $message->getRole(self::DEFAULT_ROLE),
					'content' => $message->getContent(),
				];
			}
			unset($this->params['collect_context']);
		}

		// user message (payload)
		$data[] = [
			'role' => self::DEFAULT_ROLE,
			'content' => $this->payload->getData(),
		];

		return $data;
	}

	/**
	 * @inheritDoc
	 */
	protected function getPostParams(): array
	{
		return ['messages' => $this->getPreparedMessages()];
	}

	/**
	 * @inheritDoc
	 */
	public function getResultFromRaw(mixed $rawResult, bool $cached = false): Result
	{
		$text = $rawResult['choices'][0]['message']['content'] ?? null;
		$text = $this->restoreReplacements($text);
		$rawResult['choices'][0]['message']['content'] = $text;

		return new Result($rawResult, $text, $cached);
	}

	/**
	 * @inheritDoc
	 */
	protected function makeRequestParams(array $postParams = []): array
	{
		if (empty($postParams))
		{
			$postParams = parent::makeRequestParams();
		}

		$postParams['n'] = $postParams['n'] ?? self::VARIANTS;

		return $postParams;
	}

}