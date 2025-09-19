<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Repository;

use Bitrix\Intranet\Internal\Entity\UserBaseInfoCollection;
use Bitrix\Intranet\Internal\Entity\UserBaseInfo;
use Bitrix\Intranet\Internal\Entity\UserProfile\UserProfile;
use Bitrix\Intranet\Internal\Entity\UserProfile\Gratitude;
use Bitrix\Intranet\Internal\Entity\UserProfile\GratitudeCollection;
use Bitrix\Intranet\Internal\Entity\UserProfile\GratitudeBadge;
use Bitrix\Intranet\Internal\Entity\UserProfile\GratitudeBadgeCollection;

class UserGratitudeRepository
{
	public function createGratitudeBadgesFromGratsBadgesData(array $gratsBadgesData): GratitudeBadgeCollection
	{
		$userProfileGratitudesCollection = new GratitudeBadgeCollection();

		foreach ($gratsBadgesData as $gratitudeTypeId => $gratitude)
		{
			$userProfileGratitudesCollection->add(
				new GratitudeBadge(
					gratitudeTypeId: $gratitudeTypeId,
					count: $gratitude['COUNT'],
					title: $gratitude['NAME'],
				)
			);
		}

		return $userProfileGratitudesCollection;
	}

	public function createGratitudeCollectionFromGratsPostsDataAndAuthorCollection(
		array $gratsPostsData,
		UserBaseInfoCollection $authorCollection,
	): GratitudeCollection
	{
		$userGratitudeCollection = new GratitudeCollection();

		foreach ($gratsPostsData as $postData)
		{
			$author = $authorCollection->findById((int)$postData['AUTHOR_ID']);

			if ($author instanceof UserBaseInfo)
			{
				$gratitude = new Gratitude(
					postId: (int)$postData['ID'],
					gratitudeTypeId: (int)$postData['BADGE_ID'],
					author: $author,
					title: $postData['TITLE'],
					dateTimeCreate: $postData['DATE_PUBLISH'],
				);
				$userGratitudeCollection->add($gratitude);
			}
		}

		return $userGratitudeCollection;
	}
}
