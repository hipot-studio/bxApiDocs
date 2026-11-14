<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Settings\Policy;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Access\Settings\SettingAccessPolicyInterface;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Config\Option;

/**
 * @internal
 */
final class FilePreviewerToggleEnabledPolicy implements SettingAccessPolicyInterface
{
	private const OPTION_NAME = 'allow_disable_file_previewer_in_kanban_and_grid';

	public function check(SettingInterface $setting, UserPermissions $permissions): Result
	{
		$allowed = Option::get('crm', self::OPTION_NAME, 'N') === 'Y';
		if ($allowed)
		{
			return new Result();
		}

		return (new Result())->addError(
			new Error(
				Loc::getMessage('CRM_SETTINGS_POLICY_FILE_PREVIEWER_TOGGLE_ENABLED_DENIED'),
				'CRM_SETTINGS_POLICY_FILE_PREVIEWER_TOGGLE_ENABLED_DENIED',
			),
		);
	}
}
