<?php

namespace Bitrix\Crm\Security\Role\Manage\Permissions;

use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\ControlMapper\BaseControlMapper;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\ControlMapper\Toggler;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\Variants;
use Bitrix\Main\Localization\Loc;

/**
 * Allows card observers to update the cards they observe.
 * Not related to the OBSERVER role preset.
 */
class ObserversWrite extends Permission
{
	public const CODE = 'OBSERVERS_WRITE';

	/** UI-only value of the per-stage variant that falls back to the value of the block */
	public const INHERIT = 'OBSERVERS_WRITE_INHERIT';

	/**
	 * Stage awareness belongs to the instance: blocks of stageful entities configure the permission
	 * per stage, the rest keep the plain toggle.
	 */
	public function __construct(
		?Variants $variants = null,
		?BaseControlMapper $controlMapper = null,
		private bool $canAssignPermissionToStages = false,
	)
	{
		parent::__construct($variants, $controlMapper);
	}

	public function code(): string
	{
		return self::CODE;
	}

	public function name(): string
	{
		return Loc::getMessage('CRM_SECURITY_ROLE_PERMS_HEAD_OBSERVERS_WRITE');
	}

	public function explanation(): ?string
	{
		return Loc::getMessage('CRM_SECURITY_ROLE_PERMS_EXPLANATION_OBSERVERS_WRITE');
	}

	public function canAssignPermissionToStages(): bool
	{
		return $this->canAssignPermissionToStages;
	}

	public function getDefaultAttribute(): ?string
	{
		return \Bitrix\Crm\Service\UserPermissions::PERMISSION_NONE;
	}

	/**
	 * The permission stays off even for the "full access" preset: it is enabled manually only.
	 * This caps presets, not the matrix: the allow/forbid mass actions of the rights screen
	 * take their values from the control mapper, which overrides this cap with the allowing value.
	 */
	public function getMaxAttributeValue(): ?string
	{
		return \Bitrix\Crm\Service\UserPermissions::PERMISSION_NONE;
	}

	protected function createDefaultControlMapper(): BaseControlMapper
	{
		return new Toggler();
	}

	public function getHeadDefaultAttributeValue(): ?string
	{
		return \Bitrix\Crm\Service\UserPermissions::PERMISSION_NONE;
	}

	public function getDeputyDefaultAttributeValue(): ?string
	{
		return \Bitrix\Crm\Service\UserPermissions::PERMISSION_NONE;
	}
}
