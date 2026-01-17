<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Repository\User\Profile;

use Bitrix\HumanResources\Item\Node;
use Bitrix\Intranet\Component\UserProfile\Form;
use Bitrix\Intranet\Entity\Collection\UserCollection;
use Bitrix\Intranet\Entity\Department;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Exception\UpdateFailedException;
use Bitrix\Intranet\Exception\UserFieldTypeException;
use Bitrix\Intranet\Exception\WrongIdException;
use Bitrix\Intranet\Internal\Entity\User\Field\ConvertableToUserFieldValue;
use Bitrix\Intranet\Internal\Entity\User\Field\FieldCollection;
use Bitrix\Intranet\Internal\Entity\User\Profile\FieldSectionCollection;
use Bitrix\Intranet\Internal\Entity\User\Profile\BaseInfo;
use Bitrix\Intranet\Internal\Entity\User\Field\Field;
use Bitrix\Intranet\Internal\Entity\User\Profile\FieldSection;
use Bitrix\Intranet\Internal\Entity\User\Profile\Profile;
use Bitrix\Intranet\Internal\Factory\User\UserFieldFactory;
use Bitrix\Intranet\Internal\Integration;
use Bitrix\Intranet\Internal\Provider\Profile\ProfileUserFieldComponentConfig;
use Bitrix\Intranet\Internals\Trait\UserUpdateError;
use Bitrix\Intranet\Service\IntranetOption;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\SystemException;

class ProfileRepository
{
	use UserUpdateError;

	private \CUser $cUser;
	private ?array $visibleFields;
	private array $profileSections = [];

	public function __construct(
		private UserFieldFactory $userFieldFactory,
		private Integration\Humanresources\DepartmentRepository $departmentRepository,
		private Integration\Humanresources\TeamRepository $teamRepository,
		private Integration\Ui\Form\Configuration $formConfiguration,
		private IntranetOption $option,
		private ProfileUserFieldComponentConfig $config,
	)
	{
		global $USER;
		$this->cUser = $USER instanceof \CUser ? $USER : new \CUser();
	}

	public static function createByDefault(): static
	{

		return new static(
			UserFieldFactory::createByDefault(),
			new Integration\Humanresources\DepartmentRepository(),
			new Integration\Humanresources\TeamRepository(),
			Integration\Ui\Form\Configuration::createByDefault(),
			new IntranetOption(),
			new ProfileUserFieldComponentConfig(ModuleManager::isModuleInstalled('bitrix24'))
		);
	}

	/**
	 * @throws ObjectNotFoundException
	 */
	public function getUserDataById(int $userId): array
	{
		$user = $this->fetchUser($userId);
		$user['UF_DEPARTMENT'] = $this->getUserDepartmentIds($userId);
		$user['DEPARTMENT'] = $this->getUserDepartmentNames($userId);
		$user['DEPARTMENT_HEAD'] = $this->getUserDepartmentHeads($userId);
		$user['TEAM'] = $this->getUserTeamNames($userId);

		return $user;
	}

	protected function fetchUser(int $userId): array
	{
		$filter = ['ID_EQUAL_EXACT' => $userId];
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
			'SELECT' => ['UF_PHONE_INNER', 'UF_SKYPE', 'UF_SKYPE_LINK', 'UF_ZOOM', 'UF_PUBLIC'],
		];
		$dbUser = \CUser::GetList('id', 'asc', $filter, $params);
		$user = $dbUser->fetch();

		if (!$user)
		{
			throw new ObjectNotFoundException('User not found');
		}

		return $user;
	}

	private function getUserDepartmentIds(int $userId): array
	{
		$userDepartments = $this->departmentRepository->getDepartmentsByUserId($userId);

		return array_values($userDepartments->map(fn(Department $department) => $department->getId()));
	}

	private function getUserDepartmentNames(int $userId): array
	{
		$userDepartments = $this->departmentRepository->getDepartmentsByUserId($userId);

		return $userDepartments->map(fn(Department $department) => $department->getName());
	}

	private function getUserDepartmentHeads(int $userId): UserCollection
	{
		return $this->departmentRepository
			->getDepartmentHeadsByUserId($userId)
		;
	}

	private function getUserTeamNames(int $userId): array
	{
		$userTeams = $this->teamRepository->getAllByUserId($userId);

		return isset($userTeams) ? array_values($userTeams->map(fn(Node $team) => $team->name)) : [];
	}

	/**
	 * @throws ObjectNotFoundException
	 * @throws SystemException
	 * @throws WrongIdException
	 */
	public function getById(int $userId): Profile
	{
		if ($userId <= 0)
		{
			throw new WrongIdException();
		}

		$profileData = $this->getUserDataById($userId);
		$profileForm = new Form($userId);

		return $this->createUserProfile($profileForm, $profileData);
	}

	public function getUserFieldsByUserData(array $userData): FieldCollection
	{
		$profileForm = new Form();
		$profileFieldInfo = $this->getProfileFieldInfo($profileForm, $userData);

		return $this->createUserFieldCollection($profileFieldInfo, $userData);
	}

	public function getUserBaseInfoByUserData(array $userData): BaseInfo
	{
		$user = User::initByArray($userData);

		return BaseInfo::createByUserEntity($user);
	}

	/**
	 * @param int $userId
	 * @param FieldCollection $userFieldCollection
	 * @throws UpdateFailedException
	 */
	public function saveUserProfileFields(int $userId, FieldCollection $userFieldCollection): void
	{
		$userFieldArray = $this->createArrayFromUserFieldCollection($userFieldCollection);
		$result = $this->cUser->Update($userId, $userFieldArray);

		if (!$result)
		{
			throw new UpdateFailedException($this->getErrorCollectionFromUpdateLastError($this->cUser->LAST_ERROR));
		}
	}

	private function createArrayFromUserFieldCollection(FieldCollection $userFieldCollection): array
	{
		$result = [];

		/** @var Field $userField */
		foreach ($userFieldCollection as $userField)
		{
			$value = $userField->getValue();
			$result[$userField->getId()] = $value instanceof ConvertableToUserFieldValue
				? $value->toUserFieldValue()
				: $value;
		}

		return $result;
	}

	private function createUserProfile(Form $profileForm, array $profileData): Profile
	{
		$profileFieldInfo = $this->getProfileFieldInfo($profileForm, $profileData);
		$userFieldCollection = $this->createUserFieldCollection(
			$profileFieldInfo,
			$profileForm->getData(['User' => $profileData]),
		);
		$profileSectionCollection = $this->createSectionCollection(
			$this->profileSections,
			$userFieldCollection,
		);

		return new Profile(
			baseInfo: $this->getUserBaseInfoByUserData($profileData),
			fieldSectionCollection: $profileSectionCollection,
		);
	}

	private function createSectionCollection(
		array $profileSections,
		FieldCollection $userFieldCollection,
	): FieldSectionCollection
	{
		$profileSectionCollection = new FieldSectionCollection();
		$visibleFields = $this->getVisibleFields();

		foreach ($profileSections as $profileSection)
		{
			$sectionUserFieldCollection = $this->getSectionUserFields($profileSection, $userFieldCollection, $visibleFields);
			$section = $this->createFieldSection($profileSection, $sectionUserFieldCollection);
			$profileSectionCollection->add($section);
		}

		if (!$userFieldCollection->isEmpty())
		{
			$this->addUserFieldsToDefaultSection($userFieldCollection, $profileSectionCollection);
		}

		return $profileSectionCollection;
	}

	private function getSectionUserFields(array $profileSection, FieldCollection $userFieldCollection, ?array $visibleFields): FieldCollection
	{
		$sectionUserFieldCollection = new FieldCollection();

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

		if (isset($visibleFields))
		{
			$sectionUserFieldCollection->sortByIdOrder($visibleFields);
		}

		return $sectionUserFieldCollection;
	}

	private function createFieldSection(array $profileSection, FieldCollection $sectionUserFieldCollection): FieldSection
	{
		return new FieldSection(
			id: $profileSection['name'],
			title: $profileSection['title'],
			isEditable: $profileSection['data']['isChangeable'],
			isRemovable: $profileSection['data']['isRemovable'],
			userFieldCollection: $sectionUserFieldCollection,
			isDefault: $profileSection['data']['isDefault'] ?? false,
		);
	}

	private function addUserFieldsToDefaultSection(
		FieldCollection $userFieldCollection,
		FieldSectionCollection $profileSectionCollection,
	): void
	{
		$defaultSection = $profileSectionCollection->find(
			fn(FieldSection $section) => $section->isDefault,
		);

		if (isset($defaultSection))
		{
			foreach ($userFieldCollection as $userField)
			{
				$defaultSection->userFieldCollection->add($userField);
			}
		}
	}

	private function createUserFieldCollection(
		array $profileFieldInfo,
		array $profileData,
	): FieldCollection
	{
		$userFieldCollection = new FieldCollection();
		$visibleFields = $this->getVisibleFields() ?? $this->getDefaultVisibleFields();

		foreach ($profileFieldInfo as $fieldInfo)
		{
			if (
				!isset($fieldInfo['name'])
				|| !array_key_exists($fieldInfo['name'], $profileData)
			)
			{
				continue;
			}

			$fieldInfo['isVisible'] = in_array($fieldInfo['name'], $visibleFields);

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

	private function getFormFieldInfoParams(): array
	{
		$showYear = $this->option->get('user_profile_show_year');

		if (is_string($showYear))
		{
			return [
				'SHOW_YEAR' => $showYear,
			];
		}

		return [];
	}

	private function getVisibleFields(): ?array
	{
		$this->visibleFields ??= $this->formConfiguration->getUserProfileFieldNames();

		return $this->visibleFields;
	}

	private function getProfileFieldInfo(Form $profileForm, array $profileData)
	{
		$profileFieldInfo = $profileForm->getFieldInfo($profileData, [], $this->getFormFieldInfoParams(), false);

		if (!$this->config->isCloud)
		{
			$result = ['FormFields' => $profileFieldInfo];
			$profileForm->prepareSettingsFields(
				$result,
				$this->config->get(),
			);
			$fieldsForConfig = $result['SettingsFieldsForConfig'];
			$profileFieldInfo = $result['FormFields'];
		}

		$this->profileSections = $profileForm->getNewConfig($fieldsForConfig ?? []);

		return $profileFieldInfo;
	}

	private function getDefaultVisibleFields(): array
	{
		$config = $this->profileSections;
		$fieldNames = [];

		foreach ($config as $section)
		{
			if (!empty($section['elements']) && is_array($section['elements']))
			{
				foreach ($section['elements'] as $element)
				{
					if (isset($element['name']))
					{
						$fieldNames[] = $element['name'];
					}
				}
			}
		}

		return $fieldNames;
	}
}
