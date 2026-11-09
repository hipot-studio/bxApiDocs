<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Connector\AI;

use Bitrix\AI\Context;
use Bitrix\AI\Engine;
use Bitrix\Main;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;

abstract class BaseConnector implements IConnector, UserAwareConnectorInterface
{
	protected const CATEGORY = 'text';

	protected const CONTEXT_ID = 'landing_site_copilot';
	protected const DEFAULT_TEMPERATURE = 0.7;
	protected const MAX_TOKENS = 16383;

	protected const PROMPT_CODE = 'zero_prompt';
	protected const PROMPT_CATEGORY = '';

	protected bool $active = true;
	protected ?Engine $engine;
	protected float $temperature = self::DEFAULT_TEMPERATURE;
	private ?int $userId = null;

	/**
	 * @throws LoaderException
	 */
	public function __construct()
	{
		if (!Loader::includeModule('ai'))
		{
			$this->active = false;
		}
		else
		{
			$engineCode = static::getEngineCode();
			if ($engineCode)
			{
				$this->active = true;
				$this->engine = Engine::getByCode(
					$engineCode,
					new Context('landing', static::CONTEXT_ID),
					static::CATEGORY
				);
			}
		}
	}

	/**
	 * @inheritdoc
	 */
	abstract public function request(Prompt $prompt): Main\Result;

	abstract protected function getEngineCode(): ?string;

	/**
	 * @inheritdoc
	 */
	public function setUserId(int $userId): void
	{
		if ($this->userId === $userId)
		{
			return;
		}

		$this->userId = $userId;
		// the engine keeps the context it was created with
		if (isset($this->engine))
		{
			$this->engine = Engine::getByCode(
				$this->engine->getCode(),
				new Context('landing', static::CONTEXT_ID, $userId),
				static::CATEGORY
			);
		}
	}

	protected function canRequest(): bool
	{
		return $this->active && isset($this->engine);
	}

	/**
	 * Whether a request can reach the model now: the ai module is present and the engine is set up.
	 */
	public function isAvailable(): bool
	{
		return $this->canRequest();
	}

	public function setTemperature(float $temperature): self
	{
		$this->temperature = $temperature;

		return $this;
	}
}