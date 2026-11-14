<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Tracking\Ad;

use Bitrix\Crm\V2\Internal\Service\Tracking\Ad\AccountService;

final class Accounts
{
	public function __construct(private readonly AccountService $service = new AccountService())
	{
	}

	public function authorize(int $userId, int $sourceId): string
	{
		return $this->service->authorize($userId, $sourceId);
	}

	public function list(int $userId, int $sourceId): array
	{
		return $this->service->list($userId, $sourceId);
	}
}
