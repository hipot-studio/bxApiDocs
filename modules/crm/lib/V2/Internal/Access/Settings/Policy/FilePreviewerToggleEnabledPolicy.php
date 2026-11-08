<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Settings\Policy;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Access\Settings\SettingAccessPolicyInterface;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;
use Bitrix\Main\Config\Option;

/**
 * @internal
 */
final class FilePreviewerToggleEnabledPolicy implements SettingAccessPolicyInterface
{
	private const OPTION_NAME = 'allow_disable_file_previewer_in_kanban_and_grid';

	public function isAllowed(SettingInterface $setting, UserPermissions $permissions): bool
	{
		return Option::get('crm', self::OPTION_NAME, 'N') === 'Y';
	}
}
