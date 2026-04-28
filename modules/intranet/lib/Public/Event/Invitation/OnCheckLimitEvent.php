<?php

namespace Bitrix\Intranet\Public\Event\Invitation;

use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class OnCheckLimitEvent extends Event
{
	public function __construct(
		int $totalEmailInvitedToday,
		int $totalPhoneInvitedToday,
		int $totalCurrentEmailInvitation,
		int $totalCurrentPhoneInvitation,
	)
	{
		parent::__construct('intranet', 'OnCheckLimit', [
			'totalEmailInvitedToday' => $totalEmailInvitedToday,
			'totalPhoneInvitedToday' => $totalPhoneInvitedToday,
			'totalCurrentEmailInvitation' => $totalCurrentEmailInvitation,
			'totalCurrentPhoneInvitation' => $totalCurrentPhoneInvitation,
		]);
	}
	
	public function getErrorCollection(): ErrorCollection
	{
		$errorCollection = new ErrorCollection();
		foreach ($this->getResults() as $eventResult)
		{
			if ($eventResult->getType() === EventResult::ERROR)
			{
				$parameters = $eventResult->getParameters();
				if (isset($parameters['message']))
				{
					$errorCollection[] = new Error(
						$parameters['message'],
					);
				}
			}
		}
		
		return $errorCollection;
	}
}
