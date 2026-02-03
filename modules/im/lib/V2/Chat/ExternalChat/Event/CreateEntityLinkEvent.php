<?php

namespace Bitrix\Im\V2\Chat\ExternalChat\Event;

use Bitrix\Im\V2\Chat\EntityLink\EntityLinkDto;

class CreateEntityLinkEvent extends ChatEvent
{
	public function getEntityLinkDto(): ?EntityLinkDto
	{
		$entityLinkDto = $this->getParameterFromResult('entityLinkDto');
		return ($entityLinkDto instanceof EntityLinkDto) ? $entityLinkDto : null;
	}

	protected function getActionName(): string
	{
		return "CreateEntityLink";
	}
}
