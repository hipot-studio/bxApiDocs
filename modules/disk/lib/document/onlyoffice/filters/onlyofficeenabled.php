<?php

namespace Bitrix\Disk\Document\OnlyOffice\Filters;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class OnlyOfficeEnabled extends ActionFilter\Base
{
	public function __construct(private readonly ?string $vibeofficeSessionParameter = null)
	{
		parent::__construct();
	}

	public function onBeforeAction(Event $event)
	{
		if (OnlyOfficeHandler::isEnabled())
		{
			return null;
		}

		if ($this->vibeofficeSessionParameter !== null)
		{
			$session = $this->getAction()->getArguments()[$this->vibeofficeSessionParameter] ?? null;
			if ($session instanceof DocumentSession && $session->getService() === DocumentService::Vibeoffice)
			{
				return null;
			}
		}

		$this->addError(new Error('OnlyOffice handler is not configured and not enabled.'));

		return new EventResult(EventResult::ERROR, null, null, $this);
	}
}
