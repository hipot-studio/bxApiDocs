<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\OnlyOffice\Clients\CommandService\Commands;

class ForceSaveCommand extends BaseCommand
{
	public function __construct(
		public readonly string $key,
	)
	{
		parent::__construct(CommandType::ForceSave);
	}
}
