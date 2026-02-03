<?php

namespace Bitrix\Im\V2\Chat\EntityLink;

use Bitrix\Im\V2\Chat\EntityLink;
use Bitrix\Im\V2\Chat\ExtendedType;
use Bitrix\Main\Loader;

class TasksType extends EntityLink
{

	public function __construct(EntityLinkDto $entityLinkDto)
	{
		parent::__construct($entityLinkDto);
		$this->type = ExtendedType::Tasks->value;
	}

	protected function getUrl(): string
	{
		if (!Loader::includeModule('tasks'))
		{
			return '';
		}

		$url = \CTasksTools::GetOptionPathTaskUserEntry(SITE_ID, "/company/personal/user/#user_id#/tasks/task/view/#task_id#/");
		$url = str_replace(['#user_id#', '#task_id#'], [$this->getContext()->getUserId(), $this->entityId], mb_strtolower($url));

		return $url;
	}
}
