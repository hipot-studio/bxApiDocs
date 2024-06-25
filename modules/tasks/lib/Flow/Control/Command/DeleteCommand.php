<?php

namespace Bitrix\Tasks\Flow\Control\Command;

use Bitrix\Tasks\Flow\Control\AbstractCommand;
use Bitrix\Tasks\Internals\Attribute\Min;
use Bitrix\Tasks\Internals\Attribute\Primary;

/**
 * @method self setId(int $id)
 */
final class DeleteCommand extends AbstractCommand
{
	#[Primary]
	#[Min(1)]
	public int $id;
}