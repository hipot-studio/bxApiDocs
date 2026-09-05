<?php

namespace Bitrix\Im\V2\Chat\ExternalChat\Event;

interface InitiatedByUserInterface
{
	public function getInitiatorId(): int;
}
