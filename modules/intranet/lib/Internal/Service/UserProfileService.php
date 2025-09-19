<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service;

use Bitrix\Intranet\Component\UserProfile\Grats;
use Bitrix\Intranet\Exception\UpdateFailedException;
use Bitrix\Intranet\Internal\Entity\UserProfile\GratitudeBadgeCollection;
use Bitrix\Intranet\Internal\Entity\UserField\UserFieldCollection;
use Bitrix\Intranet\Internal\Entity\UserProfile\GratitudeCollection;
use Bitrix\Intranet\Internal\Entity\UserBaseInfoCollection;
use Bitrix\Intranet\Internal\Entity\UserField\UserField;
use Bitrix\Intranet\Internal\Entity\UserProfile\UserFieldSection;
use Bitrix\Intranet\Internal\Entity\UserProfile\UserProfile;
use Bitrix\Intranet\Internal\Repository\UserGratitudeRepository;
use Bitrix\Intranet\Internal\Repository\UserProfileRepository;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;

class UserProfileService
{
	public function __construct(
		private UserProfileRepository $profileRepository,
		private UserGratitudeRepository $gratitudeRepository
	)
	{}

	public static function createByDefault(): UserProfileService
	{
		return new UserProfileService(
			new UserProfileRepository(),
			new UserGratitudeRepository(),
		);
	}

	/**
	 * @throws UpdateFailedException
	 * @throws ArgumentException
	 */
	public function updateUserFields(int $userId, UserFieldCollection $userFieldCollection): void
	{
		$userFieldCollectionToSave = new UserFieldCollection();
		$userFieldCollectionInvalid = new UserFieldCollection();

		/** @var UserField $userField */
		foreach ($userFieldCollection as $userField)
		{
			if ($userField->isValid())
			{
				$userFieldCollectionToSave->add($userField);
			}
			else
			{
				$userFieldCollectionInvalid->add($userField);
			}
		}

		if (!$userFieldCollectionInvalid->isEmpty())
		{
			$exception = new UpdateFailedException();
			$userFieldCollectionInvalid->map(
				fn (UserField $userField) => $exception->addError(
					new Error('Invalid type of field ' . $userField->getId())
				)
			);

			throw $exception;
		}

		$this->profileRepository->saveUserProfileFields($userId, $userFieldCollectionToSave);
	}

	public function getGratitudeBadges(int $userId): GratitudeBadgeCollection
	{
		$userGrats = new Grats([
			'profileId' => $userId,
		]);

		return $this->gratitudeRepository->createGratitudeBadgesFromGratsBadgesData(
			$userGrats->getGratitudes()['BADGES']
		);
	}

	public function getUserGratitudeCollection(
		int $userId,
		int $limit = 20,
		int $offset = 0,
	): GratitudeCollection
	{
		if ($limit <= 0 || $offset < 0)
		{
			return new GratitudeCollection();
		}

		$userGrats = new Grats([
			'profileId' => $userId,
			'pageSize' => $limit,
		]);

		$page = (int)floor($offset / $limit) + 1;
		$userGratsData = $userGrats->getGratitudePostListAction([
			'pageSize' => $limit,
			'pageNum' => $page,
		]);
		$authorCollection = new UserBaseInfoCollection();

		foreach ($userGratsData['AUTHORS'] as $author)
		{
			$authorCollection->add(
				$this->profileRepository->getUserBaseInfoByUserData($author)
			);
		}

		return $this->gratitudeRepository->createGratitudeCollectionFromGratsPostsDataAndAuthorCollection(
			$userGratsData['POSTS'],
			$authorCollection,
		);
	}
}
