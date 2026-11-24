<?php

namespace Bitrix\AI;

use Bitrix\AI\Context\Language;
use Bitrix\AI\Engine\ResponseFormat;
use Bitrix\AI\Facade\User;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/**
 * AI request context
 */
class Context
{
	private const FEATURE_CODE_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';

	private mixed $contextParams = null;
	private ?Language $contextLanguage = null;
	private array $eventGetMessagesNextStep = [];
	private string $featureCode = '';

	/**
	 * @param string $moduleId Module that makes the request.
	 * @param string $contextId Scenario of the request within the module.
	 * @param int|null $userId Request's user: 0 for a background request, null for the current user.
	 * @param ResponseFormat $responseFormat Format of the result delivered to the consumer.
	 */
	public function __construct(
		private string $moduleId,
		private string $contextId,
		private ?int $userId = null,
		private ResponseFormat $responseFormat = ResponseFormat::DEFAULT,
	)
	{
	}

	public static function getFake(): self
	{
		return new self('fake', 'fake');
	}

	public function isFake(): bool
	{
		return $this->moduleId === 'fake' && $this->contextId === 'fake';
	}

	public function getModuleId(): string
	{
		return $this->moduleId;
	}

	public function getContextId(): string
	{
		return $this->contextId;
	}

	/**
	 * Returns the request's user id; the current user if it was not passed to the constructor.
	 *
	 * @return int
	 */
	public function getUserId(): int
	{
		return $this->userId ?? User::getCurrentUserId();
	}

	public function setParameters(mixed $params): void
	{
		$this->contextParams = $params;
	}

	public function getParameters(): mixed
	{
		return $this->contextParams;
	}

	public function getResponseFormat(): ResponseFormat
	{
		return $this->responseFormat;
	}

	/**
	 * Sets the feature code: a stable scenario name that labels AI usage in place of the context ID.
	 * A code is 1-64 bytes of [A-Za-z0-9_-]; any other value leaves the code empty, resetting a previous one.
	 * Keep codes within 30 bytes: FeatureTag cuts the scenario part of the label to fit its budget,
	 * so codes that differ only beyond the cut share one label.
	 *
	 * @param string $featureCode Feature code.
	 * @return void
	 */
	public function setFeatureCode(string $featureCode): void
	{
		$this->featureCode = preg_match(self::FEATURE_CODE_PATTERN, $featureCode) === 1 ? $featureCode : '';
	}

	public function getFeatureCode(): string
	{
		return $this->featureCode;
	}

	/**
	 * If context must have special language - set them.
	 * @param Language|string $language
	 * @return void
	 */
	public function setLanguage(Language|string $language): void
	{
		if (is_string($language))
		{
			$this->contextLanguage = new Language($language);
		}
		else
		{
			$this->contextLanguage = $language;
		}
	}

	/**
	 * Get context language, if set
	 * @return Language|null
	 */
	public function getLanguage(): ?Language
	{
		return $this->contextLanguage;
	}

	/**
	 * Returns context messages for current Context.
	 * For example, you can send another post's comments in current request.
	 * In this case, external Engine will understand the context and be able to answer more accurately.
	 *
	 * Messages are collected from onContextGetMessages handlers: each successful EventResult may return
	 * `messages` (arrays with `content`, optional `role`, `meta`, `is_original_message`) and `next_step`,
	 * which is passed to handlers on the next call; a null `next_step` stops further calls.
	 *
	 * @return Context\Message[]
	 */
	public function getMessages(): array
	{
		$messages = [];
		$eventId = $this->getModuleId() . $this->getContextId();

		if (
			array_key_exists($eventId, $this->eventGetMessagesNextStep)
			&& $this->eventGetMessagesNextStep[$eventId] === null
		)
		{
			return [];
		}

		$event = new Event('ai', 'onContextGetMessages', [
			'module' => $this->getModuleId(),
			'id' => $this->getContextId(),
			'params' => $this->getParameters(),
			'next_step' => $this->eventGetMessagesNextStep[$eventId] ?? null,
		]);
		$event->send();

		foreach ($event->getResults() as $eventResult)
		{
			if ($eventResult->getType() !== EventResult::ERROR)
			{
				$parameters = $eventResult->getParameters();
				if (empty($parameters['messages']) || !is_array($parameters['messages']))
				{
					continue;
				}

				$messages = array_merge($messages, Context\Message::retrieveFromArrays($parameters['messages']));

				$this->eventGetMessagesNextStep[$eventId] = $parameters['next_step'] ?? null;
			}
		}

		return $messages;
	}

	/**
	 * Packs the Context's data in string and returns it.
	 *
	 * @return string
	 */
	public function pack(): string
	{
		$data = [
			'moduleId' => $this->getModuleId(),
			'contextId' => $this->getContextId(),
			'userId' => $this->getUserId(),
			'parameters' => $this->getParameters(),
			'responseFormat' => $this->getResponseFormat(),
			'languageCode' => $this->getLanguage()?->getCode(),
		];

		// Without a code the package keeps its previous bytes: it is a part of the queue result cache key.
		if ($this->featureCode !== '')
		{
			$data['featureCode'] = $this->featureCode;
		}

		return json_encode($data);
	}

	/**
	 * Unpacks data and creates Context instance from it.
	 *
	 * @param string $packedData Packed data by using method self::pack().
	 * @return self
	 */
	public static function unpack(string $packedData): self
	{
		$data = json_decode($packedData, true);
		if (json_last_error() !== JSON_ERROR_NONE)
		{
			throw new \InvalidArgumentException(
				'Packed context contains invalid JSON: ' . json_last_error_msg(),
			);
		}
		if (!is_array($data) || array_is_list($data))
		{
			throw new \InvalidArgumentException('Packed context root must be an object');
		}

		$requiredTypes = [
			'moduleId' => ['string'],
			'contextId' => ['string'],
			'userId' => ['integer', 'NULL'],
			'parameters' => null,
			'responseFormat' => ['string', 'NULL'],
			'languageCode' => ['string', 'NULL'],
		];
		foreach ($requiredTypes as $field => $types)
		{
			if (!array_key_exists($field, $data))
			{
				throw new \InvalidArgumentException('Packed context is missing field ' . $field);
			}
			if ($types !== null && !in_array(gettype($data[$field]), $types, true))
			{
				throw new \InvalidArgumentException(
					'Packed context field ' . $field . ' has invalid type ' . get_debug_type($data[$field]),
				);
			}
		}

		[
			'moduleId' => $moduleId,
			'contextId' => $contextId,
			'userId' => $userId,
			'parameters' => $parameters,
			'responseFormat' => $responseFormat,
			'languageCode' => $languageCode,
		] = $data;

		$context = new self(
			$moduleId,
			$contextId,
			$userId,
			ResponseFormat::fromString($responseFormat),
		);
		$context->setParameters($parameters);
		if ($languageCode !== null)
		{
			$context->setLanguage($languageCode);
		}

		$featureCode = $data['featureCode'] ?? null;
		if (is_string($featureCode))
		{
			$context->setFeatureCode($featureCode);
		}

		return $context;
	}

	/**
	 * Removes all about Context.
	 *
	 * @param array<string>|string $context Specified context.
	 * @return void
	 */
	public static function clearContext(array|string $context): void
	{
		History\Manager::clearHistoryByContext($context);
	}
}
