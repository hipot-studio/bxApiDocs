<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Pull\Event\SharingLink;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\BaseEvent;
use Bitrix\Im\V2\SharingLink\SharingLink;

abstract class BaseSharingLinkEvent extends BaseEvent
{
	protected SharingLink $sharingLink;

	public function __construct(SharingLink $sharingLink)
	{
		$this->sharingLink = $sharingLink;

		parent::__construct();
	}

	protected function getRecipients(): array
	{
		return $this->sharingLink->getRecipientsForPull($this->getType());
	}

	public function getTarget(): ?Chat
	{
		return null;
	}
}
