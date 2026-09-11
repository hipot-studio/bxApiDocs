<?php

namespace Bitrix\Sign\Agent\Permission;

use Bitrix\Main\Loader;
use Bitrix\Sign\Access\Permission\PermissionDictionary as CrmPermissionDictionary;
use Bitrix\Sign\Service\Container;

class UpdateDefaultTemplatePermissionAgent
{
	public static function run(): string
	{
		if (!Loader::includeModule('crm'))
		{
			return '';
		}

		$permissionsService = Container::instance()->getPermissionsService();
		$result = $permissionsService->copyPermissionValuesForAllRoles(
			CrmPermissionDictionary::getB2eDocumentToTemplatePermissionMap(),
		);
		if (!$result->isSuccess())
		{
			Container::instance()->getLogger('Agent')->error('UpdateDefaultTemplatePermissionAgent error: ' . implode(', ', $result->getErrorMessages()));

			return '';
		}

		return '';
	}
}
