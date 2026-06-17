<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;


class TemplateRecentRepository implements TemplateRecentRepositoryInterface
{
	private const CATEGORY = 'tasks';
	private const OPTION_NAME = 'template_recent';

	public function get(int $userId): array
	{
		return \CUserOptions::GetOption(
			category: self::CATEGORY,
			name: self::OPTION_NAME,
			default_value: [],
			user_id: $userId,
		);
	}

	public function save(int $userId, array $recentIds): void
	{
		\CUserOptions::SetOption(
			category: self::CATEGORY,
			name: self::OPTION_NAME,
			value: array_values($recentIds),
			user_id: $userId,
		);
	}
}
