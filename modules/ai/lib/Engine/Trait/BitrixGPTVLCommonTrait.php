<?php declare(strict_types=1);

namespace Bitrix\AI\Engine\Trait;

use Bitrix\AI\Config;
use Bitrix\AI\Context\Message;
use Bitrix\AI\Facade\Bitrix24;
use Bitrix\AI\Image\ImageReference;
use Bitrix\AI\Payload\Text;
use Bitrix\AI\Quality;
use Bitrix\AI\Result;
use Bitrix\AI\Tokenizer\GPT;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\SystemException;

trait BitrixGPTVLCommonTrait
{
	protected function getSystemParameters(): array
	{
		return [
			'model' => $this->getModel(),
			'temperature' => self::TEMPERATURE,
			'max_tokens' => self::MAX_TOKENS,
		];
	}

	public function setResponseJsonMode(bool $enable): void
	{
		$this->isModeResponseJson = $enable;
	}

	protected function getMessageLength(Message $message): int
	{
		$content = $message->getContent();

		return $this->getTokenizer()->count($content);
	}

	/**
	 * Builds multimodal content array from text and ImageReference objects.
	 *
	 * @param string $text Text content.
	 * @param ImageReference[] $images
	 * @return string|array Plain string if no images, multimodal array otherwise.
	 */
	private function buildMultimodalContent(string $text, array $images = []): string|array
	{
		if (empty($images))
		{
			return $text;
		}

		$userId = $this->getContext()->getUserId();

		$content = [];
		$content[] = ['type' => 'text', 'text' => $text];

		foreach ($images as $image)
		{
			$content[] = [
				'type' => 'image_url',
				'image_url' => [
					'url' => $image->toApiUrl($userId),
				],
			];
		}

		return $content;
	}

	/**
	 * Resolves raw image arrays from context message meta into ImageReference objects.
	 *
	 * @param array|null $rawImages Raw arrays from $message->getMeta('images').
	 * @return ImageReference[]
	 */
	private function resolveImagesFromMeta(?array $rawImages): array
	{
		if (empty($rawImages))
		{
			return [];
		}

		$resolved = [];
		foreach ($rawImages as $raw)
		{
			$ref = ImageReference::fromArray($raw);
			if ($ref !== null)
			{
				$resolved[] = $ref;
			}
		}

		return $resolved;
	}

	private function getPreparedMessages(): array
	{
		$data = [];

		// system role (instruction) — always plain text
		if ($role = $this->payload->getRole())
		{
			$data[] = [
				'role' => self::SYSTEM_ROLE,
				'content' => $role->getInstruction(),
			];
		}

		// context messages (multi-turn history)
		if ($this->params['collect_context'] ?? false)
		{
			foreach ($this->getMessages() as $message)
			{
				$images = $this->resolveImagesFromMeta($message->getMeta('images'));
				$content = $this->buildMultimodalContent(
					$message->getContent(),
					$images
				);

				$data[] = [
					'role' => $message->getRole(self::DEFAULT_ROLE),
					'content' => $content,
				];
			}
			unset($this->params['collect_context']);
		}

		if ($this->payload::class === Text::class)
		{
			$this->payload->setPayload(str_replace(['/think', '/no_think'], '', $this->payload->getData()));
		}

		// current user message (payload + images from engine)
		$content = $this->buildMultimodalContent(
			$this->payload->getData(),
			$this->images
		);

		$data[] = [
			'role' => self::DEFAULT_ROLE,
			'content' => $content,
		];

		return $data;
	}

	/**
	 * @inheritDoc
	 */
	protected function getPostParams(): array
	{
		$postParams = ['messages' => $this->getPreparedMessages()];

		$payload = $this->getPayload();
		if (method_exists($payload, 'getJsonSchema') && $payload->getJsonSchema() !== null)
		{
			$postParams['response_format'] = [
				'type' => 'json_schema',
				'json_schema' => $payload->getJsonSchema(),
			];
			$this->isModeResponseJson = true;
		}
		elseif ($this->isModeResponseJson)
		{
			$postParams['response_format'] = ['type' => 'json_object'];
		}

		return $postParams;
	}

	protected function getCompletionsUrl(): string
	{
		throw new SystemException(
			'getCompletionsUrl() must be overridden in ' . static::class
		);
	}

	public function isPreferredForQuality(?Quality $quality = null): bool
	{
		return false;
	}

	public function getResultFromRaw(mixed $rawResult, bool $cached = false): Result
	{
		$text = $rawResult['choices'][0]['message']['content'] ?? null;
		$dataJson = null;

		$text = $this->restoreReplacements($text);
		$rawResult['choices'][0]['message']['content'] = $text;

		if ($text && $this->isModeResponseJson)
		{
			$text = trim($text, " \n\r\t\v\0`");
			$dataJson = json_decode($text, true) ?? null;
		}

		return new Result($rawResult, $text, $cached, $dataJson);
	}

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
	 * Check if engine is available for current region.
	 */
	public function isAvailable(): bool
	{
		$region = Application::getInstance()->getLicense()->getRegion();

		$availableByRegion = $region === 'ru' || $region === 'by';
		if (!$availableByRegion)
		{
			return false;
		}

		$shouldUseB24 = Bitrix24::shouldUseB24();
		if (!$shouldUseB24)
		{
			return true;
		}

		return Config::getValue('bitrixgpt_vl_enabled') === 'Y';
	}

	/**
	 * @inheritDoc
	 */
	protected function makeRequestParams(array $postParams = []): array
	{
		$params = parent::makeRequestParams($postParams);
		$reasoningEffort = $params['reasoning_effort'] ?? null;
		unset($params['reasoning_effort']);
		$enableThinking = $this->reasoningMode || !empty($reasoningEffort);

		$params['chat_template_kwargs'] = [
			'enable_thinking' => $enableThinking,
		];

		return $params;
	}

	protected function getTokenizer(): GPT
	{
		return ServiceLocator::getInstance()->get(GPT::class);
	}
}
