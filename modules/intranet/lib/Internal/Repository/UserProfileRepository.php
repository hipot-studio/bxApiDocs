<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Repository;

use Bitrix\Intranet\Component\UserProfile\Form;
use Bitrix\Intranet\Entity\Department;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Exception\UpdateFailedException;
use Bitrix\Intranet\Exception\UserFieldTypeException;
use Bitrix\Intranet\Exception\WrongIdException;
use Bitrix\Intranet\Internal\Entity\UserField\UserFieldCollection;
use Bitrix\Intranet\Internal\Entity\UserProfile\UserFieldSectionCollection;
use Bitrix\Intranet\Internal\Entity\UserBaseInfo;
use Bitrix\Intranet\Internal\Entity\UserField\UserField;
use Bitrix\Intranet\Internal\Entity\UserProfile\UserFieldSection;
use Bitrix\Intranet\Internal\Entity\UserProfile\UserProfile;
use Bitrix\Intranet\Internal\Factory\User\UserFieldFactory;
use Bitrix\Intranet\Internals\Trait\UserUpdateError;
use Bitrix\Intranet\Repository\HrDepartmentRepository;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\SystemException;

class UserProfileRepository
{
	use UserUpdateError;

	private \CUser $cUser;
	private UserFieldFactory $userFieldFactory;

	public function __construct()
	{
		global $USER;
		$this->cUser = $USER instanceof \CUser ? $USER : new \CUser();
		$this->userFieldFactory = new UserFieldFactory();
	}

	public function getUserDataById(int $userId): array
	{
		$filter = [
			'ID_EQUAL_EXACT' => $userId
		];

		$params = [
			'FIELDS' => [
				'ID', 'ACTIVE', 'CONFIRM_CODE', 'EXTERNAL_AUTH_ID', 'LAST_ACTIVITY_DATE', 'DATE_REGISTER',
				'LOGIN', 'EMAIL', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'WORK_POSITION',
				'PERSONAL_PHOTO', 'PERSONAL_BIRTHDAY', 'PERSONAL_GENDER',
				'PERSONAL_WWW', 'PERSONAL_MOBILE', 'WORK_PHONE', 'PERSONAL_CITY',
				'TIME_ZONE', 'AUTO_TIME_ZONE', 'TIME_ZONE_OFFSET',
				'PERSONAL_COUNTRY', 'PERSONAL_FAX', 'PERSONAL_MAILBOX',
				'PERSONAL_PHONE', 'PERSONAL_STATE', 'PERSONAL_STREET', 'PERSONAL_ZIP',
				'WORK_CITY', 'WORK_COUNTRY', 'WORK_COMPANY', 'WORK_DEPARTMENT',
				'PERSONAL_PROFESSION', 'WORK_NOTES', 'WORK_PROFILE', 'LANGUAGE_ID',
			],
			'SELECT' => [ 'UF_PHONE_INNER', 'UF_SKYPE', 'UF_SKYPE_LINK', 'UF_ZOOM', 'UF_PUBLIC' ]
		];

		$dbUser = \CUser::GetList('id', 'asc', $filter, $params);
		$user = $dbUser->fetch();

		if (!$user)
		{
			throw new ObjectNotFoundException('User not found');
		}

		$user['UF_DEPARTMENT'] = array_values(
			(new HrDepartmentRepository())
				->findAllByUserId($userId)
				->map(fn (Department $department) => $department->getId())
		);

		return $user;
	}

	/**
	 * @throws ObjectNotFoundException
	 * @throws SystemException
	 * @throws WrongIdException
	 */
	public function getById(int $userId): UserProfile
	{
		if ($userId <= 0)
		{
			throw new WrongIdException();
		}

		$profileData = $this->getUserDataById($userId);
		$profileForm = new Form($userId);

		return $this->createUserProfileByProfileFormAndProfileData($profileForm, $profileData);
	}

	public function getUserFieldsByUserData(array $userData): UserFieldCollection
	{
		$profileForm = new Form();

		return $this->createUserFieldCollectionByProfileFormAndProfileData(
			$profileForm,
			$userData,
		);
	}

	public function getUserBaseInfoByUserData(array $userData): UserBaseInfo
	{
		$user = User::initByArray($userData);
		$fullName = \CUser::FormatName(\CSite::GetNameFormat(), $userData, false, false);

		return new UserBaseInfo(
			userId: (int)$userData['ID'],
			fullName: $fullName,
			userRole: $user->getRole(),
			invitationStatus: $user->getInviteStatus(),
			photoId: isset($userData['PERSONAL_PHOTO']) ? (int)$userData['PERSONAL_PHOTO'] : null,
		);
	}

	/**
	 * @param int $userId
	 * @param UserFieldCollection $userFieldCollection
	 * @throws UpdateFailedException
	 */
	public function saveUserProfileFields(int $userId, UserFieldCollection $userFieldCollection): void
	{
		$userFieldArray = $this->createArrayFromUserFieldCollection($userFieldCollection);

		$result = $this->cUser->Update($userId, $userFieldArray);

		if (!$result)
		{
			throw new UpdateFailedException($this->getErrorCollectionFromUpdateLastError($this->cUser->LAST_ERROR));
		}
	}

	private function createArrayFromUserFieldCollection(UserFieldCollection $userFieldCollection): array
	{
		$result = [];

		/** @var UserField $userField */
		foreach ($userFieldCollection as $userField)
		{
			$result[$userField->getId()] = $userField->getValue();
		}

		return $result;
	}

	private function createUserProfileByProfileFormAndProfileData(Form $profileForm, array $profileData): UserProfile
	{
		$profileSections = $profileForm->getNewConfig();
		$userFieldCollection = $this->createUserFieldCollectionByProfileFormAndProfileData(
			$profileForm,
			$profileForm->getData(['User' => $profileData])
		);

		$profileSectionCollection = $this->createSectionCollectionFromProfileSectionsAndUserFields(
			$profileSections,
			$userFieldCollection,
		);

		return new UserProfile(
			baseInfo: $this->getUserBaseInfoByUserData($profileData),
			fieldSectionCollection: $profileSectionCollection,
		);
	}

	private function createSectionCollectionFromProfileSectionsAndUserFields(
		array $profileSections,
		UserFieldCollection $userFieldCollection,
	): UserFieldSectionCollection
	{
		$profileSectionCollection = new UserFieldSectionCollection();

		foreach ($profileSections as $profileSection)
		{
			$sectionUserFieldCollection = new UserFieldCollection();

			foreach ($profileSection['elements'] as $element)
			{
				$userFieldId = $element['name'] ?? null;

				if (empty($userFieldId))
				{
					continue;
				}

				$userField = $userFieldCollection->findById($userFieldId);

				if (isset($userField))
				{
					$sectionUserFieldCollection->add($userField);
					$userFieldCollection->removeItem($userField);
				}
			}

			$section = new UserFieldSection(
				id: $profileSection['name'],
				title: $profileSection['title'],
				isEditable: $profileSection['data']['isChangeable'],
				isRemovable: $profileSection['data']['isRemovable'],
				userFieldCollection: $sectionUserFieldCollection,
				isDefault: $profileSection['data']['isDefault'] ?? false,
			);

			$profileSectionCollection->add($section);
		}

		if (!$userFieldCollection->isEmpty())
		{
			$this->addUserFieldsToDefaultSection($userFieldCollection, $profileSectionCollection);
		}

		return $profileSectionCollection;
	}

	private function addUserFieldsToDefaultSection(
		UserFieldCollection $userFieldCollection,
		UserFieldSectionCollection $profileSectionCollection,
	): void
	{
		/* @var UserFieldSection $defaultSection */
		$defaultSection = $profileSectionCollection->find(
			fn (UserFieldSection $section) => $section->isDefault
		);

		if (isset($defaultSection))
		{
			foreach ($userFieldCollection as $userField)
			{
				$defaultSection->userFieldCollection->add($userField);
			}
		}
	}

	private function createUserFieldCollectionByProfileFormAndProfileData(
		Form $profileForm,
		array $profileData,
	): UserFieldCollection
	{
		$profileFieldInfo = $profileForm->getFieldInfo($profileData);
		$userFieldCollection = new UserFieldCollection();

		foreach ($profileFieldInfo as $fieldInfo)
		{
			if (
				!isset($fieldInfo['name'])
				|| !isset($profileData[$fieldInfo['name']])
			)
			{
				continue;
			}

			try
			{
				$userField = $this->userFieldFactory->createUserFieldByArray($fieldInfo, $profileData[$fieldInfo['name']]);
			}
			catch (UserFieldTypeException|ArgumentException)
			{
				continue;
			}

			$userFieldCollection->add($userField);
		}

		return $userFieldCollection;
	}
}
