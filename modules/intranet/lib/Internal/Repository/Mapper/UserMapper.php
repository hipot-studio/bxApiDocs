<?php

namespace Bitrix\Intranet\Internal\Repository\Mapper;

use Bitrix\Intranet\Entity\User;
use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\Date;

class UserMapper
{
	public function convertFromArray(array $userData): User
	{
		$active = null;
		if (!empty($userData['ACTIVE']))
		{
			$active = $userData['ACTIVE'] === 'Y';
		}

		if (!empty($userData['PHONE_NUMBER']) && empty($userData['AUTH_PHONE_NUMBER']))
		{
			$userData['AUTH_PHONE_NUMBER'] = $userData['PHONE_NUMBER'];
		}

		return new User(
			id: $userData['ID'] ?? null,
			login: $userData['LOGIN'] ?? null,
			email: $userData['EMAIL'] ?? null,
			name: $userData['NAME'] ?? null,
			lastName: $userData['LAST_NAME'] ?? null,
			confirmCode: $userData["CONFIRM_CODE"] ?? null,
			groupIds: $userData['GROUP_ID'] ?? null,
			xmlId: $userData['XML_ID'] ?? null,
			active: $active,
			externalAuthId: $userData['EXTERNAL_AUTH_ID'] ?? null,
			authPhoneNumber: $userData['AUTH_PHONE_NUMBER'] ?? null,
			secondName: $userData['SECOND_NAME'] ?? null,
			personalPhoto: $userData['PERSONAL_PHOTO'] ?? null,
			lid: $userData['LID'] ?? null,
			languageId: $userData['LANGUAGE_ID'] ?? null,
			personalMobile: $userData['PERSONAL_MOBILE'] ?? null,
			personalGender: $userData['PERSONAL_GENDER'] ?? null,
			workPosition: $userData['WORK_POSITION'] ?? null,
			password: $userData['PASSWORD'] ?? null,
			ufCrmEntity: $userData['UF_USER_CRM_ENTITY'] ?? null,
			lastLogin: $this->parseDateValue($userData['LAST_LOGIN'] ?? null),
		);
	}

	public function convertToArray(User $user, ?array $fieldNames = null): array
	{
		$fieldMap = $fieldNames === null ? null : array_flip($fieldNames);
		$isRequired = static fn(string $fieldName): bool => $fieldMap !== null && isset($fieldMap[$fieldName]);

		$userData = [
			'ID' => $user->getId() ?? null,
			'LOGIN' => $user->getLogin() ?? null,
			'EMAIL' => $user->getEmail() ?? null,
			'CONFIRM_CODE' => $user->getConfirmCode() ?? null,
			'NAME' => $user->getName() ?? null,
			'LAST_NAME' => $user->getLastName() ?? null,
			'LID' => $user->getLid() ?? null,
			'PERSONAL_PHOTO' => $user->getPersonalPhoto() ?? null,
			'LANGUAGE_ID' => $user->getLanguageId() ?? null,
			'XML_ID' => $user->getXmlId() ?? null,
			'EXTERNAL_AUTH_ID' => $user->getExternalAuthId() ?? null,
			'SECOND_NAME' => $user->getSecondName() ?? null,
			'PASSWORD' => $user->getPassword() ?? null,
			'UF_USER_CRM_ENTITY' => $user->getUfCrmEntity() ?? null,
		];

		if ($user->getPhoneNumber() || $isRequired('PHONE_NUMBER'))
		{
			$userData['PHONE_NUMBER'] = $user->getPhoneNumber();
		}

		if (!is_null($user->getGroupIds()) || $isRequired('GROUP_ID'))
		{
			$userData['GROUP_ID'] = $user->getGroupIds();
		}

		if ($user->getPersonalMobile() || $isRequired('PERSONAL_MOBILE'))
		{
			$userData['PERSONAL_MOBILE'] = $user->getPersonalMobile();
		}

		if (!is_null($user->getGender()))
		{
			$userData['PERSONAL_GENDER'] = $user->getGender();
		}

		if (!is_null($user->getActive()) || $isRequired('ACTIVE'))
		{
			$userData['ACTIVE'] = $user->getActive() ? 'Y' : 'N';
		}

		if (!is_null($user->getLastLogin()) || $isRequired('LAST_LOGIN'))
		{
			$userData['LAST_LOGIN'] = $user->getLastLogin();
		}

		if ($fieldMap !== null)
		{
			$userData = array_intersect_key($userData, $fieldMap);
		}

		return $userData;
	}

	private function parseDateValue($value): ?Date
	{
		if ($value instanceof Date)
		{
			return $value;
		}

		if (is_string($value))
		{
			try
			{
				return new Date($value);
			}
			catch (ObjectException)
			{
				return null;
			}
		}

		return null;
	}
}
