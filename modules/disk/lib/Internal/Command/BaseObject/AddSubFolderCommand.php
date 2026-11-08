<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Command\BaseObject;

use Bitrix\Disk\Folder;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

class AddSubFolderCommand extends AbstractCommand
{
	/**
	 * @param Folder $folder
	 * @param string $name
	 * @param int $createdBy
	 * @param bool $generateUniqueName
	 * @param bool $shouldCheckUnifiedLink
	 */
	public function __construct(
		public readonly Folder $folder,
		public readonly string $name,
		public readonly int $createdBy,
		public readonly bool $generateUniqueName = false,
		public readonly bool $shouldCheckUnifiedLink = false,
	)
	{
	}

	/**
	 * @return Result
	 * @throws ArgumentException
	 */
	protected function execute(): Result
	{
		return (new AddSubFolderCommandHandler())($this);
	}
}
