<?php

namespace Bitrix\Main\Cli\Input;

use InvalidArgumentException;
use RuntimeException;

class InputSourceReader
{
	public function read(?string $filePath, string $standardInput): string
	{
		$hasFile = $filePath !== null && $filePath !== '';
		$hasStandardInput = $standardInput !== '';

		if ($hasFile && $hasStandardInput)
		{
			throw new InvalidArgumentException(
				'Pass data via --file or standard input, but not both',
			);
		}

		if (!$hasFile && !$hasStandardInput)
		{
			throw new InvalidArgumentException(
				'Pass non-empty input data',
			);
		}

		$input = $hasFile ? $this->readFile($filePath) : $standardInput;
		if (trim($input) === '')
		{
			throw new InvalidArgumentException(
				'Pass non-empty input data',
			);
		}

		return $input;
	}

	private function readFile(string $filePath): string
	{
		$content = @file_get_contents($filePath);
		if ($content === false)
		{
			throw new RuntimeException(
				sprintf('Failed to read file %s', $filePath),
			);
		}

		return $content;
	}
}
