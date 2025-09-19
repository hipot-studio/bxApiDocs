<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Provider\User;

use Bitrix\Intranet\Internal\Entity\UserProfile\GratitudeCollection;
use Bitrix\Intranet\Internal\Entity\UserProfile\GratitudeBadgeCollection;
use Bitrix\Intranet\Internal\Service\UserProfileService;
use Bitrix\Main\Provider\Params\GridParams;

class UserGratitudeProvider
{
	public function __construct(
		private UserProfileService $profileService,
	)
	{}

	public static function createByDefault(): UserGratitudeProvider
	{
		return new UserGratitudeProvider(
			UserProfileService::createByDefault(),
		);
	}

	public function getList(
		int $userId,
		GridParams $gridParams
	): GratitudeCollection
	{
		return $this->profileService->getUserGratitudeCollection(
			userId: $userId,
			limit: $gridParams->getLimit(),
			offset: $gridParams->getOffset(),
		);
	}

	public function getProfileBadges(int $userId): GratitudeBadgeCollection
	{
		return $this->profileService->getGratitudeBadges(
			userId: $userId,
		);
	}
}
