<?php

namespace Bitrix\DocumentGenerator\Engine;

use Bitrix\DocumentGenerator\Controller\Document;
use Bitrix\DocumentGenerator\Integration\Bitrix24Manager;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

final class CheckPermissionsFeature extends Base
{
	public function onBeforeAction(Event $event)
	{
		if (!Bitrix24Manager::isPermissionsFeatureEnabled())
		{
			$this->errorCollection[] = new Error(
				message: 'Your plan does not support this operation',
				code: Document::ERROR_ACCESS_DENIED,
				customData: [
					'sliderCode' => 'limit_crm_document_access_permissions',
				],
			);

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
