<?php

namespace Bitrix\Intranet\Internal\Service;

use Bitrix\Intranet\Exception\UpdateFailedException;
use Bitrix\Intranet\Internal\Entity\Collection\UserFieldCollection;
use Bitrix\Intranet\Internal\Entity\UserField\UserField;
use Bitrix\Intranet\Internal\Entity\UserFieldSection;
use Bitrix\Intranet\Internal\Entity\UserProfile;
use Bitrix\Intranet\Internal\Repository\UserProfileRepository;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;

class UserProfileService
{
	public function __construct(
		private UserProfileRepository $userProfileRepository,
	)
	{}

	public static function createByDefault(): UserProfileService
	{
		return new UserProfileService(
			new UserProfileRepository()
		);
	}

	/**
	 * @throws UpdateFailedException
	 * @throws ArgumentException
	 */
	public function updateUserProfile(UserProfile $userProfile): void
	{
		$userId = $userProfile->userId;
		$userFieldCollectionToSave = new UserFieldCollection();
		$userFieldCollectionInvalid = new UserFieldCollection();

		/** @var UserFieldSection $section */
		foreach ($userProfile->fieldSectionCollection as $section)
		{
			/** @var UserField $userField */
			foreach ($section->userFieldCollection as $userField)
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

		$this->userProfileRepository->saveUserProfileFields($userId, $userFieldCollectionToSave);

		// todo: save UserBadges, UserInterests, etc
	}
}
