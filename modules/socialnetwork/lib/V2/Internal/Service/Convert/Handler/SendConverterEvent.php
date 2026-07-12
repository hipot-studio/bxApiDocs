<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler;

use Bitrix\Socialnetwork\Collab\Converter\Event\ConverterEvent;
use Bitrix\Socialnetwork\Item\Workgroup;

class SendConverterEvent implements HandlerInterface
{
	public function __invoke(Workgroup $groupBefore, Workgroup $groupAfter): void
	{
		(new ConverterEvent($groupBefore, $groupAfter))->send();
	}
}
