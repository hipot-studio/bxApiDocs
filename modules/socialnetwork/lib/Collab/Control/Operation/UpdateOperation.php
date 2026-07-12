<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Control\Operation;

use Bitrix\Socialnetwork\Collab\Control\Mapper\CollabMapper;
use Bitrix\Socialnetwork\Control;
use Bitrix\Socialnetwork\Control\Mapper\Mapper;

class UpdateOperation extends Control\Operation\UpdateOperation
{
	protected function createMapper(): Mapper
	{
		return new CollabMapper();
	}

	protected function shouldRunSync(): bool
	{
		// Collab departments are stored in HR relations, not in legacy UF_SG_DEPT sync.
		return false;
	}
}
