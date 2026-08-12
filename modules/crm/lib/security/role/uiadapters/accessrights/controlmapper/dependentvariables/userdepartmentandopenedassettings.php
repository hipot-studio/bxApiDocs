<?php

namespace Bitrix\Crm\Security\Role\UIAdapters\AccessRights\ControlMapper\DependentVariables;

class UserDepartmentAndOpenedAsSettings extends UserDepartmentAndOpenedBase
{
	public function getValueForUi(?string $attr, ?array $settings)
	{
		if (is_array($settings) && !empty($settings))
		{
			if (in_array(\Bitrix\Crm\Security\Role\Manage\AttrPreset\UserDepartmentAndOpened::ALL, $settings, true))
			{
				return $this->permissionPreset->getPermissiveSettingsVariantsList();
			}

			return array_unique($settings);
		}

		return $this->permissionPreset->convertSingleToMultiValue((string)$attr);  // compatibility mode
	}

	public function getAttrFromUiValue(array $value): ?string
	{
		return null;
	}

	public function getSettingsFromUiValue(array $value): ?array
	{
		return $value;
	}

	public function convertAttributeToSettings(string $attribute): array
	{
		return $this->permissionPreset->convertSingleToMultiValue($attribute);
	}
}
