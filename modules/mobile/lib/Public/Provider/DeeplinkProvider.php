<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Public\Provider;

use Bitrix\Mobile\Internal\Service\Deeplink\AuthLinkService;

/**
 * Deeplinks of the mobile application for other modules: the returned address opens the
 * application on the page named by the intent code and signs the user in.
 */
final class DeeplinkProvider
{
	public function __construct(
		private readonly AuthLinkService $authLinkService = new AuthLinkService(),
	) {}

	/**
	 * @param string $intent code of the page the application opens
	 * @param int|null $userId link recipient, the current user by default
	 * @param int|null $ttl lifetime of the authentication hash in seconds, the portal default by default
	 */
	public function getAuthLink(string $intent, ?int $userId = null, ?int $ttl = null): string
	{
		return (string)$this->authLinkService->issue($intent, $userId, $ttl);
	}
}
