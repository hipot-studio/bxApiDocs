<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\HtmlEditor;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class InlineImageLimit
{
	public const ERROR_LIMIT = 'inline_image_too_large';
	private const MAX_BYTES = 10 * 1024 * 1024;

	public function validate(string $previousHtml, string $html): Result
	{
		$previous = [];
		foreach ($this->urls($previousHtml) as $url)
		{
			$previous[hash('sha256', $url)] = true;
		}
		foreach ($this->urls($html) as $url)
		{
			if (isset($previous[hash('sha256', $url)]))
			{
				continue;
			}
			if (preg_match('/^data:image\/(png|jpeg|gif|webp);base64,/', $url, $matches) !== 1)
			{
				return $this->error();
			}
			$encoded = substr($url, strlen($matches[0]));
			if (strlen($encoded) > 4 * (int)ceil(self::MAX_BYTES / 3) + 4)
			{
				return $this->error();
			}
			$decoded = base64_decode($encoded, true);
			if (
				$decoded === false
				|| strlen($decoded) > self::MAX_BYTES
				|| !$this->matchesImageType($decoded, $matches[1])
			)
			{
				return $this->error();
			}
		}

		return new Result();
	}

	private function matchesImageType(string $bytes, string $type): bool
	{
		return match ($type)
		{
			'png' => str_starts_with($bytes, "\x89PNG\r\n\x1a\n"),
			'jpeg' => str_starts_with($bytes, "\xff\xd8\xff"),
			'gif' => str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a'),
			'webp' => str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP',
			default => false,
		};
	}

	private function urls(string $html): \Generator
	{
		$offset = 0;
		while (($start = stripos($html, 'data:image/', $offset)) !== false)
		{
			$length = strcspn($html, "\"' \t\r\n>", $start);
			yield substr($html, $start, $length);
			$offset = $start + max($length, 1);
		}
	}

	private function error(): Result
	{
		return (new Result())->addError(new Error(self::ERROR_LIMIT, self::ERROR_LIMIT));
	}
}
