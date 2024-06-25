<?php

namespace Bitrix\Tasks\Flow\Integration\Socialnetwork;

use Bitrix\Tasks\Flow\Control\AbstractCommand;
use Bitrix\Tasks\Internals\Attribute\ExpectedNumeric;
use Bitrix\Tasks\Internals\Attribute\Min;
use Bitrix\Tasks\Internals\Attribute\Nullable;
use Bitrix\Tasks\Internals\Attribute\Required;

/**
 * @method self setName(string $name)
 * @method self setOwnerId(int $ownerId)
 * @method self setMembers(array $members)
 */
class GroupCommand extends AbstractCommand
{
	#[Nullable]
	public int $id;

	#[Required]
	public string $name;

	#[Required]
	#[Min(1)]
	public int $ownerId;

	#[Nullable]
	#[ExpectedNumeric]
	public array $members;
}