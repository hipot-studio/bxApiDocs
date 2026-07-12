<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Event\Project;

use Bitrix\Main\Event;

class OnProjectCreated extends Event
{
	public function __construct(public readonly int $id, public readonly int $ownerId)
	{
		parent::__construct('socialnetwork', 'OnProjectCreated', ['id' => $id, 'ownerId' => $ownerId]);
	}
}
