<?php

namespace Bitrix\Im\V2\Chat\ExternalChat\Event;

use Bitrix\Im\V2\Chat\ExternalChat\Event;
use Bitrix\Im\V2\Result;

class AfterCreateEvent extends Event
{
	public function __construct(string $entityType, Result $createResult)
	{
		parent::__construct($entityType, ['createResult' => $createResult]);
	}

	public function getCreateResult(): Result
	{
		return $this->parameters['createResult'];
	}

	protected function getActionName(): string
	{
		return 'AfterCreate';
	}
}