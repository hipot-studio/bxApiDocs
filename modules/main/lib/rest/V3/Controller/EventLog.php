<?php
namespace Bitrix\Main\Rest\V3\Controller;

use Bitrix\Main\Rest\V3\Dto\EventLogDto;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\ActionFilter\UserCanDoOperation;
use Bitrix\Rest\V3\Controller\GetOrmActionTrait;
use Bitrix\Rest\V3\Controller\ListOrmActionTrait;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Controller\TailOrmActionTrait;

#[DtoType(EventLogDto::class)]
class EventLog extends RestController
{
	use ListOrmActionTrait;
	use GetOrmActionTrait;
	use TailOrmActionTrait;

	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new UserCanDoOperation(['view_event_log']),
		];
	}
}