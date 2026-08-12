<?php
namespace Bitrix\Landing\AI\SiteBuilder\Parser;

use Bitrix\Main\Web\Json;

final class TolerantJsonParser implements TolerantJsonParserInterface
{
	public function parse(string $payload): mixed
	{
		$decoded = $this->decode($payload);
		if ($decoded !== null)
		{
			return $decoded;
		}

		$decoded = $this->parseObject($payload);
		if ($decoded !== null)
		{
			return $decoded;
		}

		return $this->parseArray($payload);
	}

	public function parseArray(string $payload): ?array
	{
		$decoded = $this->decode($payload);
		if (is_array($decoded))
		{
			return $decoded;
		}

		$candidate = $this->stripTrailingCommas(
			$this->extractJsonArray(
				$this->stripCodeFences($payload)
			)
		);
		$decoded = $this->decodeWithEscapedQuotes($candidate);

		return is_array($decoded) ? $decoded : null;
	}

	public function parseObject(string $payload): ?array
	{
		$decoded = $this->decode($payload);
		if ($this->isObjectArray($decoded))
		{
			return $decoded;
		}

		$candidate = $this->stripTrailingCommas(
			$this->extractJsonObject(
				$this->stripCodeFences($payload)
			)
		);
		$decoded = $this->decodeWithEscapedQuotes($candidate);

		return $this->isObjectArray($decoded) ? $decoded : null;
	}

	private function decode(string $payload): mixed
	{
		try
		{
			return Json::decode($payload);
		}
		catch (\Throwable $exception)
		{
			return null;
		}
	}

	private function decodeWithEscapedQuotes(string $payload): mixed
	{
		$decoded = $this->decode($payload);
		if ($decoded !== null)
		{
			return $decoded;
		}

		return $this->decode($this->escapeInnerQuotes($payload));
	}

	private function stripCodeFences(string $value): string
	{
		if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $value, $matches))
		{
			return (string)$matches[1];
		}

		return $value;
	}

	private function extractJsonArray(string $value): string
	{
		$start = strpos($value, '[');
		$end = strrpos($value, ']');
		if ($start === false || $end === false || $end <= $start)
		{
			return $value;
		}

		return substr($value, $start, $end - $start + 1);
	}

	private function extractJsonObject(string $value): string
	{
		$start = strpos($value, '{');
		$end = strrpos($value, '}');
		if ($start === false || $end === false || $end <= $start)
		{
			return $value;
		}

		return substr($value, $start, $end - $start + 1);
	}

	private function stripTrailingCommas(string $value): string
	{
		return (string)preg_replace('/,\s*([}\]])/m', '$1', $value);
	}

	private function escapeInnerQuotes(string $value): string
	{
		$length = strlen($value);
		$output = '';
		$inString = false;
		$escaped = false;

		for ($i = 0; $i < $length; $i++)
		{
			$char = $value[$i];
			if (!$inString)
			{
				if ($char === '"')
				{
					$inString = true;
				}
				$output .= $char;
				continue;
			}

			if ($escaped)
			{
				$output .= $char;
				$escaped = false;
				continue;
			}

			if ($char === '\\')
			{
				$output .= $char;
				$escaped = true;
				continue;
			}

			if ($char === '"')
			{
				$nextIndex = $i + 1;
				while ($nextIndex < $length && preg_match('/\s/u', $value[$nextIndex]))
				{
					$nextIndex++;
				}

				$nextChar = $nextIndex < $length ? $value[$nextIndex] : '';
				if ($nextChar === ',' || $nextChar === '}' || $nextChar === ']')
				{
					$inString = false;
					$output .= $char;
				}
				else
				{
					$output .= '\\"';
				}
				continue;
			}

			$output .= $char;
		}

		return $output;
	}

	private function isObjectArray(mixed $value): bool
	{
		if (!is_array($value))
		{
			return false;
		}

		$expectedIndex = 0;
		foreach ($value as $key => $item)
		{
			if ($key !== $expectedIndex)
			{
				return true;
			}

			$expectedIndex++;
		}

		return false;
	}
}
