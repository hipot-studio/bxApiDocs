<?php

namespace Bitrix\Main\Config\Feature\Rules;

use Bitrix\Main\Config\Feature\AbstractRule;
use Bitrix\Main\Config\Feature\Context;

final class UserIdRule extends AbstractRule
{
	private array $userIds;

	public function __construct(?int ...$userIds)
	{
		$this->userIds = $userIds;
	}

	public static function createFromConfig(array $config = []): static
	{
		$userIds = $config['userIds'] ?? [];
		if (!is_array($userIds))
		{
			$userIds = [$userIds];
		}

		$userIds = array_map(
			fn($id) => $id === null ? null : (int)$id,
			array_unique($userIds),
		);

		return new static(...$userIds);
	}

	public function check(Context $context): bool
	{
		return in_array($context->userId, $this->userIds, true);
	}
}
