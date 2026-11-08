<?php

namespace Bitrix\Crm\Security\Role\Manage\Permissions;

use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\ControlMapper\BaseControlMapper;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\ControlMapper\Toggler;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\Variants;
use Bitrix\Main\Localization\Loc;

class WriteConfig extends Write
{
	public function __construct(
		?Variants $variants = null,
		?BaseControlMapper $controlMapper = null,
		private readonly bool $showExplanation = false,
		private readonly bool $isAutomatedSolutionContour = false,
	)
	{
		parent::__construct($variants, $controlMapper);
	}

	public function name(): string
	{
		return GetMessage('CRM_PERMS_PERM_WRITE');
	}

	public function explanation(): ?string
	{
		if (!$this->showExplanation)
		{
			return null;
		}

		return $this->isAutomatedSolutionContour
			? Loc::getMessage('CRM_SECURITY_ROLE_PERMS_EXPLANATION_WRITE_CONFIG_AUTOMATED_SOLUTION')
			: Loc::getMessage('CRM_SECURITY_ROLE_PERMS_EXPLANATION_WRITE_CONFIG');
	}

	protected function createDefaultControlMapper(): BaseControlMapper
	{
		return new Toggler();
	}
}
