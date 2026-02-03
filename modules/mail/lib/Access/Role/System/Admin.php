<?php

namespace Bitrix\Mail\Access\Role\System;

use Bitrix\Mail\Access\Permission\PermissionDictionary;
use Bitrix\Mail\Access\Permission\PermissionVariablesDictionary;
use Bitrix\Main\Access\Permission\PermissionDictionary as PermissionDictionaryAlias;

class Admin extends Base
{
	public function getPermissions(): array
	{
		return [
			PermissionDictionary::MAIL_ACCESS_RIGHTS_EDIT => PermissionDictionaryAlias::VALUE_YES,
			PermissionDictionary::MAIL_MAILBOX_LIST_ITEM_VIEW => PermissionVariablesDictionary::VARIABLE_ALL,
			PermissionDictionary::MAIL_MAILBOX_LIST_ITEM_EDIT => PermissionVariablesDictionary::VARIABLE_ALL,
			PermissionDictionary::MAIL_MAILBOX_CONNECT => PermissionDictionaryAlias::VALUE_YES,
			PermissionDictionary::MAIL_MAILBOX_CRM_INTEGRATION_EDIT => PermissionDictionaryAlias::VALUE_YES,
		];
	}
}
