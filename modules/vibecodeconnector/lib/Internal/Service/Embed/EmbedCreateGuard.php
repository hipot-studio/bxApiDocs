<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Embed;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibePlusPolicy;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserAttributesResolver;
use Bitrix\Vibecodeconnector\Public\Service\AvailabilityService;

final class EmbedCreateGuard
{
	public const ERROR_ACCESS_DENIED = 'ACCESS_DENIED';
	public const ERROR_FEATURE_NOT_AVAILABLE = 'FEATURE_NOT_AVAILABLE';

	public function __construct(
		private readonly AvailabilityService $availabilityService = new AvailabilityService(),
		private readonly VibePlusPolicy $vibePlusPolicy = new VibePlusPolicy(),
		private readonly UserAttributesResolver $userAttributes = new UserAttributesResolver(),
	) {
	}

	public function resolveErrorCode(EmbedView $view, int $userId): ?string
	{
		if ($userId <= 0 || !$this->availabilityService->isAvailableForUser($userId))
		{
			return self::ERROR_ACCESS_DENIED;
		}

		if ($view === EmbedView::CoworkAdmin)
		{
			return $this->isAdmin($userId) ? null : self::ERROR_ACCESS_DENIED;
		}

		if ($this->vibePlusPolicy->getAvailability() === false)
		{
			return self::ERROR_FEATURE_NOT_AVAILABLE;
		}

		return null;
	}

	public function isAllowed(EmbedView $view, int $userId): bool
	{
		return $this->resolveErrorCode($view, $userId) === null;
	}

	private function isAdmin(int $userId): bool
	{
		$currentUser = CurrentUser::get();
		if ((int)$currentUser->getId() !== $userId)
		{
			return $this->userAttributes->resolveForUser($userId)['isAdmin'];
		}

		return $currentUser->isAdmin()
			|| (Loader::includeModule('bitrix24') && \CBitrix24::IsPortalAdmin($userId))
		;
	}
}
