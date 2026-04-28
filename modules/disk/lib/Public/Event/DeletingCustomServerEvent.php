<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Event;

use Bitrix\Disk\Internal\Interface\CustomServerInterface;

class DeletingCustomServerEvent extends AbstractEvent
{
	public const EVENT_NAME = 'deletingCustomServer';

	public function __construct(CustomServerInterface $customServer)
	{
		parent::__construct(
			event: static::EVENT_NAME,
			parameters: [
				'customServer' => $customServer,
			],
		);
	}
}
