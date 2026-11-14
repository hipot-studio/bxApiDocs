<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Tracking\Ad;

use Bitrix\Crm\V2\Internal\Service\Tracking\Ad\AccountService;

/** Called only after independent browser session and CSRF verification. */
final class AuthorizeBrowser
{
	public function __construct(private readonly AccountService $service = new AccountService())
	{
	}

	public function execute(int $userId, string $ticket, string $sessionHash): string
	{
		return $this->service->authorizeBrowser($userId, $ticket, $sessionHash);
	}
}
