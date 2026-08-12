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
	private array $markers = [];
	private ?Schema $schema = null;
	private ?int $cost = null;
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
		$this->markers = is_array($markers) ? $this->normalizeMarkers($markers) : [];

		return $this;
	}

	public function getMarkers(): array
	{
		return $this->markers;
	}

	private function normalizeMarkers(array $markers): array
	{
		$normalized = [];
		foreach ($markers as $key => $value)
		{
			if (!is_string($key))
			{
				$normalized[$key] = $value;
				continue;
			}

			foreach ($this->getMarkerAliases($key) as $alias)
			{
				if (!array_key_exists($alias, $normalized))
				{
					$normalized[$alias] = $value;
				}
			}
		}

		return $normalized;
	}

	private function getMarkerAliases(string $key): array
	{
		$trimmedKey = trim($key);
		if (preg_match('/^\{\{([a-zA-Z0-9_]+)\}\}$/', $trimmedKey, $matches))
		{
			return [
				'{' . $matches[1] . '}',
				$matches[1],
				$key,
			];
		}

		if (preg_match('/^\{([a-zA-Z0-9_]+)\}$/', $trimmedKey, $matches))
		{
			return [
				$key,
				$matches[1],
			];
		}

		if (preg_match('/^[a-zA-Z0-9_]+$/', $trimmedKey))
		{
			return [
				'{' . $trimmedKey . '}',
				$key,
			];
		}

		return [$key];
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

	public function setCost(int $cost): self
	{
		$this->cost = $cost;

		return $this;
	}

	public function getCost(): ?int
	{
		return $this->cost;
	}
}
