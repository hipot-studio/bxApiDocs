<?php

namespace Bitrix\Crm\Service\UserPermissions;

/**
 * The configuration capabilities of a contour ({@see ConfigurationScope}).
 *
 * Seven of them are operations of the role model and are stored in `b_crm_role_perms.PERM_TYPE` under
 * the code {@see ConfigurationOperation::getStoredCode()}; the column holds 20 characters, so a new
 * code must stay within that. The remaining two are derived and never stored: tunnels follow the
 * robots right of the source funnel and funnel access is an alias of the full configuration right, so
 * neither is offered in the role matrix and neither has a code or a label of its own.
 */
enum ConfigurationOperation
{
	case ManageFunnels;
	case ManageStages;
	case ManageFields;
	case DeleteFields;
	case ManageCommonViews;
	case ManageBizprocTemplates;
	case ManageDynamicTypes;
	case ManageTunnels;
	case ManageCategoryAccess;

	/**
	 * The operation code as it is stored in a role, `null` for a derived capability.
	 */
	public function getStoredCode(): ?string
	{
		return match ($this)
		{
			self::ManageFunnels => 'CFG_FUNNELS',
			self::ManageStages => 'CFG_STAGES',
			self::ManageFields => 'CFG_FIELDS',
			self::DeleteFields => 'CFG_FIELDS_DELETE',
			self::ManageCommonViews => 'CFG_COMMON_VIEWS',
			self::ManageBizprocTemplates => 'CFG_BIZPROC',
			self::ManageDynamicTypes => 'CFG_DYNAMIC_TYPES',
			self::ManageTunnels, self::ManageCategoryAccess => null,
		};
	}

	/**
	 * The phrase code the role matrix shows the operation under, `null` for a derived capability.
	 */
	public function getLabelKey(): ?string
	{
		return match ($this)
		{
			self::ManageFunnels => 'CRM_SECURITY_ROLE_PERMS_CONFIG_FUNNELS',
			self::ManageStages => 'CRM_SECURITY_ROLE_PERMS_CONFIG_STAGES',
			self::ManageFields => 'CRM_SECURITY_ROLE_PERMS_CONFIG_FIELDS',
			self::DeleteFields => 'CRM_SECURITY_ROLE_PERMS_CONFIG_FIELDS_DELETE',
			self::ManageCommonViews => 'CRM_SECURITY_ROLE_PERMS_CONFIG_COMMON_VIEWS',
			self::ManageBizprocTemplates => 'CRM_SECURITY_ROLE_PERMS_CONFIG_BIZPROC',
			self::ManageDynamicTypes => 'CRM_SECURITY_ROLE_PERMS_CONFIG_DYNAMIC_TYPES',
			self::ManageTunnels, self::ManageCategoryAccess => null,
		};
	}

	/**
	 * The capabilities that are operations of the role model, in the order the matrix shows them.
	 *
	 * @return self[]
	 */
	public static function storedOperations(): array
	{
		return array_values(
			array_filter(self::cases(), static fn (self $operation): bool => $operation->getStoredCode() !== null)
		);
	}
}
