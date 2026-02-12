<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Helper;

final class ArgumentExtractor
{
	public function extractString(array $args, string $key, string $default = ''): string
	{
		return trim((string)($args[$key] ?? $default));
	}

	public function extractInt(array $args, string $key, int $default): int
	{
		return (int)($args[$key] ?? $default);
	}

	public function extractLimit(array $args, int $default, int $max): int
	{
		$limit = $this->extractInt($args, 'limit', $default);
		if ($limit < 1)
		{
			$limit = $default;
		}
		elseif ($limit > $max)
		{
			$limit = $max;
		}

		return $limit;
	}

	public function extractCategoryId(array $args): ?int
	{
		return isset($args['categoryId'])
			? $this->extractInt($args, 'categoryId', 0)
			: null
		;
	}
}
