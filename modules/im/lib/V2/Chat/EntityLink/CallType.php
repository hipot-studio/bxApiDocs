<?php

namespace Bitrix\Im\V2\Chat\EntityLink;

class CallType extends CrmType
{
	public function __construct(EntityLinkDto $entityLinkDto, ?string $entityData = null)
	{
		parent::__construct($entityLinkDto);
		$this->extractCrmData($entityData ?? '');
	}

	protected function extractCrmData(string $rawCrmData): void
	{
		$separatedEntityId = explode('|', $rawCrmData);
		$this->crmType = $separatedEntityId[1] ?? '';
		$this->crmId = (int)($separatedEntityId[2] ?? 0);
	}
}