<?php

namespace Bitrix\Sign\Item\Process;

use Bitrix\Sign\Contract;
use Bitrix\Sign\Type\Process\RunningProcessStatus;

/**
 * A launch group as the top level of the list shows it. The group exists after every press of the start
 * button, one employee or thirty; what differs is the showing - a group of one employee is an ordinary row
 * of a process and does not open (AC-015a).
 *
 * The number of employees counts the processes of the group the person looking at the list may see
 * (AC-015b): the number must not tell about processes that are hidden from them.
 */
final class LaunchGroup implements Contract\Item
{
	public function __construct(
		public readonly int $launchId,
		public readonly string $processName,
		public readonly int $memberCount,
		public readonly RunningProcessStatus $status,
		public readonly int $startedTs,
		public readonly ?RunningProcess $process = null,
	)
	{
	}

	public function hasChildren(): bool
	{
		return $this->process === null;
	}
}
