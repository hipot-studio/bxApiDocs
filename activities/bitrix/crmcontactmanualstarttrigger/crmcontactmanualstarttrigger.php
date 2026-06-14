<?php

declare(strict_types=1);

use Bitrix\Bizproc\Activity\Mixins\ManualStartDocumentTrait;
use Bitrix\Bizproc\Public\Entity\Trigger\Section;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!CBPRuntime::getRuntime()->includeActivityFile('ManualStartTrigger'))
{
	return;
}

class CBPCrmContactManualStartTrigger extends \CBPManualStartTrigger
{
	use ManualStartDocumentTrait;

	public function __construct($name)
	{
		parent::__construct($name);
		$this->initManualStartDocumentProperties();
	}

	protected static function resolveDocumentType(): ?array
	{
		if (!\Bitrix\Main\Loader::includeModule('crm'))
		{
			return null;
		}

		return CCrmBizProcHelper::ResolveDocumentType(CCrmOwnerType::Contact);
	}

	protected function getSection(): ?Section
	{
		return new Section(static::getModuleId() . '|' . static::getDocument());
	}

	protected static function getModuleId(): string
	{
		return 'crm';
	}

	protected static function getDocument():string
	{
		return 'CONTACT';
	}
}
