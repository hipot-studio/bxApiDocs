<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Control\Operation;

use Bitrix\Socialnetwork\Collab\Control\Mapper\CollabMapper;
use Bitrix\Socialnetwork\Control;
use Bitrix\Socialnetwork\Control\Mapper\Mapper;

class AddOperation extends Control\Operation\AddOperation
{
	protected function createMapper(): Mapper
	{
		return new CollabMapper();
	}
}
