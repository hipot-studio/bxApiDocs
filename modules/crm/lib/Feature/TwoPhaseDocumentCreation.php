<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Crm\Feature\Category\Experimental;
use Bitrix\Main\Localization\Loc;

final class TwoPhaseDocumentCreation extends AbstractCrmFlag
{
	public function getName(): string
	{
		return (string)Loc::getMessage('CRM_FEATURE_TWO_PHASE_DOCUMENT_CREATION_NAME');
	}

	public function getCategory(): Experimental
	{
		return Experimental::getInstance();
	}

	public function enabledByDefault(): bool
	{
		return true;
	}
}
