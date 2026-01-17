<?php

namespace Bitrix\Intranet\Internal\Provider\Profile;

class ProfileUserFieldComponentConfig
{
	public function __construct(
		public readonly bool $isCloud,
	)
	{
	}

	public function get(): array
	{
		return $this->isCloud ? $this->getCloudConfig() : $this->getConfig();
	}

	private function getCloudConfig(): array
	{
		$editableFields = [
			'LOGIN',
			'NAME',
			'SECOND_NAME',
			'LAST_NAME',
			'EMAIL',
			'PASSWORD',
			'PERSONAL_BIRTHDAY',
			'PERSONAL_WWW',
			'PERSONAL_GENDER',
			'PERSONAL_PHOTO',
			'PERSONAL_MOBILE',
			'PERSONAL_CITY',
			'WORK_PHONE',
			'UF_PHONE_INNER',
			'UF_SKYPE',
			'UF_TWITTER',
			'UF_FACEBOOK',
			'UF_LINKEDIN',
			'UF_XING',
			'UF_SKILLS',
			'UF_INTERESTS',
			'UF_WEB_SITES',
			'TIME_ZONE',
			'GROUP_ID',
			'WORK_POSITION',
		];

		if ($GLOBALS['USER']->CanDoOperation('edit_all_users'))
		{
			$editableFields[] = 'UF_DEPARTMENT';
		}

		return [
			'EDITABLE_FIELDS' => $editableFields,
			'USER_FIELDS_MAIN' => [
				'PERSONAL_BIRTHDAY',
				'WORK_POSITION',
				'WORK_COMPANY',
				'SECOND_NAME',
			],
			'USER_PROPERTY_MAIN' => [
				'UF_DEPARTMENT',
				'DEPARTMENT',
				'TEAM',
				'DEPARTMENT_HEAD',
			],
			'USER_FIELDS_CONTACT' => [
				'EMAIL',
				'PERSONAL_WWW',
				'PERSONAL_MOBILE',
				'WORK_PHONE',
			],
			'USER_PROPERTY_CONTACT' => [
				'UF_PHONE_INNER',
				'UF_SKYPE',
				'UF_TWITTER',
				'UF_FACEBOOK',
				'UF_LINKEDIN',
				'UF_XING',
			],
			'USER_FIELDS_PERSONAL' => [
				'TIME_ZONE',
				'PERSONAL_CITY',
			],
			'USER_PROPERTY_PERSONAL' => [
				'UF_SKILLS',
				'UF_INTERESTS',
				'UF_WEB_SITES',
			],
		];
	}

	private function getConfig(): array
	{
		return [
			'USER_FIELDS_MAIN' => [
				'LAST_LOGIN',
				'PERSONAL_PROFESSION',
				'WORK_POSITION',
			],
			'USER_PROPERTY_MAIN' => [
				'UF_DEPARTMENT',
				'DEPARTMENT',
				'TEAM',
				'DEPARTMENT_HEAD',
			],
			'USER_FIELDS_CONTACT' => [
				'EMAIL',
				'PERSONAL_WWW',
				'PERSONAL_ICQ',
				'PERSONAL_PHONE',
				'PERSONAL_FAX',
				'PERSONAL_MOBILE',
				'WORK_WWW',
				'WORK_PHONE',
				'WORK_FAX',
			],
			'USER_PROPERTY_CONTACT' => [
				'UF_PHONE_INNER',
				'UF_SKYPE',
				'UF_TWITTER',
				'UF_FACEBOOK',
				'UF_LINKEDIN',
				'UF_XING',
			],
			'USER_FIELDS_PERSONAL' => [
				'PERSONAL_BIRTHDAY',
				'PERSONAL_GENDER',
			],
			'USER_PROPERTY_PERSONAL' => [
				'UF_SKILLS',
				'UF_INTERESTS',
				'UF_WEB_SITES',
			],
			'EDITABLE_FIELDS' => [
				'LOGIN',
				'NAME',
				'SECOND_NAME',
				'LAST_NAME',
				'EMAIL',
				'PASSWORD',
				'PERSONAL_BIRTHDAY',
				'PERSONAL_WWW',
				'PERSONAL_ICQ',
				'PERSONAL_GENDER',
				'PERSONAL_PHOTO',
				'PERSONAL_PHONE',
				'PERSONAL_FAX',
				'PERSONAL_MOBILE',
				'PERSONAL_COUNTRY',
				'PERSONAL_STATE',
				'PERSONAL_CITY',
				'PERSONAL_ZIP',
				'PERSONAL_STREET',
				'PERSONAL_MAILBOX',
				'WORK_PHONE',
				'FORUM_SHOW_NAME',
				'FORUM_DESCRIPTION',
				'FORUM_INTERESTS',
				'FORUM_SIGNATURE',
				'FORUM_AVATAR',
				'FORUM_HIDE_FROM_ONLINE',
				'FORUM_SUBSC_GET_MY_MESSAGE',
				'BLOG_ALIAS',
				'BLOG_DESCRIPTION',
				'BLOG_INTERESTS',
				'BLOG_AVATAR',
				'UF_PHONE_INNER',
				'UF_SKYPE',
				'TIME_ZONE',
				'UF_TWITTER',
				'UF_FACEBOOK',
				'UF_LINKEDIN',
				'UF_XING',
				'UF_SKILLS',
				'UF_INTERESTS',
				'UF_WEB_SITES',
				'WORK_POSITION',
			],
		];
	}
}
