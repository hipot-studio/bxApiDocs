<?php

namespace Bitrix\Crm\Service\Timeline\Item\LogMessage\AI;

use Bitrix\Main\Localization\Loc;

final class FillingEntityFieldsStarted extends Base
{
	public function getType(): string
	{
		return 'FillingEntityFieldsStarted';
	}

	public function getTitle(): ?string
	{
		if ($this->isCallAssociated())
		{
			return Loc::getMessage('CRM_TIMELINE_LOG_FILLING_FIELDS_STARTED');
		}

		if ($this->isOpenLineAssociated())
		{
			return Loc::getMessage('CRM_TIMELINE_LOG_FILLING_FIELDS_CHAT_STARTED');
		}

		return null;
	}
}
