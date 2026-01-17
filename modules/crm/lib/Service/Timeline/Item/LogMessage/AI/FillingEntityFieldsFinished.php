<?php

namespace Bitrix\Crm\Service\Timeline\Item\LogMessage\AI;

use Bitrix\Main\Localization\Loc;

final class FillingEntityFieldsFinished extends Base
{
	public function getType(): string
	{
		return 'FillingEntityFieldsFinished';
	}

	public function getTitle(): ?string
	{
		if ($this->isCallAssociated())
		{
			return Loc::getMessage('CRM_TIMELINE_LOG_FILLING_FIELDS_FINISHED');
		}

		if ($this->isOpenLineAssociated())
		{
			return Loc::getMessage('CRM_TIMELINE_LOG_FILLING_FIELDS_CHAT_FINISHED');
		}

		return null;
	}
}
