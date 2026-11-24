<?php
declare(strict_types=1);

namespace Bitrix\Disk\Realtime\Tags;

use Bitrix\Main\Loader;
use CPullWatch;

final class ObjectTag extends Tag
{
	public function __construct(private readonly int $objectId)
	{
	}

	public function getObjectId(): int
	{
		return $this->objectId;
	}

	public function getName(): string
	{
		return "object_{$this->getObjectId()}";
	}

	/**
	 * Lets the user hear the events of the object; the client keeps the watch alive by itself.
	 */
	public function subscribe(int $userId): void
	{
		if ($userId > 0 && Loader::includeModule('pull'))
		{
			CPullWatch::Add($userId, $this->getName());
		}
	}
}
