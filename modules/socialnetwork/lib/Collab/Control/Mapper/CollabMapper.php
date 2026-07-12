<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Control\Mapper;

use Bitrix\Socialnetwork\Control\Command\AbstractCommand;
use Bitrix\Socialnetwork\Control\Mapper\Mapper;

class CollabMapper extends Mapper
{
	public function toArray(AbstractCommand $command): array
	{
		$fields = parent::toArray($command);

		unset($fields['UF_SG_DEPT']);

		return $fields;
	}
}
