<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Connector\AI;

use Bitrix\Landing\Copilot\Connector\Schema\Builder\JsonShape;
use Bitrix\Landing\Copilot\Connector\Schema\Schema;
use Bitrix\Main\SystemException;

class Prompt
{
	private const DEFAULT_REQUEST_TEMPERATURE = 0.7;
	private string $code;
	private array $markers;
	private ?Schema $schema = null;
	private float $temperature;

	public function __construct(string $code)
	{
		$this->code = $code;
		$this->setTemperature(self::DEFAULT_REQUEST_TEMPERATURE);
	}

	public function getCode(): string
	{
		return $this->code;
	}

	public function setMarkers($markers): self
	{
		$this->markers = $markers;

		return $this;
	}

	public function getMarkers(): array
	{
		return $this->markers;
	}

	public function setSchema(Schema $schema): self
	{
		$this->schema = $schema;

		return $this;
	}

	public function getSchema(): ?Schema
	{
		return $this->schema;
	}

	public function getJsonSchema(): ?array
	{
		if (!$this->schema)
		{
			return null;
		}

		try
		{
			return (new JsonShape($this->schema))->build();
		}
		catch (SystemException)
		{
			return null;
		}
	}

	public function setTemperature($temperature): self
	{
		$this->temperature = $temperature;

		return $this;
	}

	public function getTemperature(): float
	{
		return $this->temperature;
	}
}