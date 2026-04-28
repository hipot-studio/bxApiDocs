<?php

namespace Bitrix\Crm\VCard;

use Bitrix\Crm\Result;

final class VCardLine
{
	private ?string $key = null;
	private array $parameters = [];
	private ?string $value = null;

	public function __construct(array $lines)
	{
		foreach ($lines as $line)
		{
			$this->parseLine($line);
		}
	}

	public function validate(): Result
	{
		if (empty($this->key))
		{
			return Result::fail('Key cannot be empty');
		}

		if (empty($this->value))
		{
			return Result::fail('Value cannot be empty');
		}

		return Result::success();
	}

	private function parseLine(string $line): void
	{
		$parts = explode(':', $line, 2);
		if (count($parts) !== 2)
		{
			return;
		}

		[$keyPart, $valuePart] = $parts;

		$this->parseKeyAndParameters($keyPart);
		$this->parseValues($valuePart);
	}

	private function parseKeyAndParameters(string $keyPart): void
	{
		if (preg_match('/^(item\d+)\.(.*)/i', $keyPart, $matches))
		{
			$keyPart = $matches[2];
		}

		$components = explode(';', $keyPart);
		foreach ($components as $index => $component)
		{
			if ($index === 0 && $this->key === null)
			{
				$this->key = mb_strtoupper(trim($component));

				continue;
			}

			$param = trim($component);
			if (!empty($param))
			{
				$this->parseParameter($param);
			}
		}
	}

	private function parseParameter(string $param): void
	{
		$paramParts = explode('=', $param, 2);

		if (count($paramParts) === 2)
		{
			$paramKey = mb_strtoupper(trim($paramParts[0]));
			$paramValue = trim($paramParts[1]);

			if (str_contains($paramValue, ','))
			{
				$this->parameters[$paramKey] = array_merge(
					$this->parameters[$paramKey] ?? [],
					array_map('trim', explode(',', $paramValue)),
				);

				return;
			}

			$this->parameters[$paramKey] ??= [];
			$this->parameters[$paramKey][] = $paramValue;

			return;
		}

		$this->parameters[mb_strtoupper(trim($param))] = true;
	}

	private function parseValues(string $valuePart): void
	{
		$this->value = $valuePart;
	}

	public function getKey(): string
	{
		return $this->key;
	}

	public function getParameters(): array
	{
		return $this->parameters;
	}

	public function getParameter(string $name)
	{
		$name = strtoupper($name);

		$parameter = $this->parameters[$name] ?? null;
		if (is_array($parameter) && count($parameter) === 1)
		{
			return $parameter[0];
		}

		return $parameter;
	}

	public function getValue(): ?string
	{
		return $this->value;
	}

	public function hasParameterValue(string $paramName, string $value): bool
	{
		$param = $this->getParameter($paramName);

		if (is_array($param))
		{
			return in_array($value, $param, true);
		}

		return $param === $value;
	}

	public function getType(): ?string
	{
		$type = $this->getParameter('TYPE');

		if (is_string($type))
		{
			return $type;
		}

		return null;
	}

	public function isType(string $type): bool
	{
		return $this->hasParameterValue('TYPE', mb_strtoupper($type));
	}

	public function getPref(): ?int
	{
		if ($this->isType('PREF'))
		{
			return 1;
		}

		return $this->getParameter('PREF');
	}
}
