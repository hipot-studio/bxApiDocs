<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Command\BaseObject;

use Bitrix\Disk\Bitrix24Disk\TmpFile;
use Bitrix\Disk\Folder;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

class CreateByContentCommand extends AbstractCommand
{
	/**
	 * @param Folder $folder
	 * @param string $filename
	 * @param TmpFile $content
	 * @param int $createdBy
	 * @param string $contentType
	 * @param bool $generateUniqueName
	 * @param bool $shouldCheckUnifiedLink
	 * @param array|null $previewFileData
	 */
	public function __construct(
		public readonly Folder $folder,
		public readonly string $filename,
		public readonly TmpFile $content,
		public readonly int $createdBy,
		public readonly string $contentType,
		public readonly bool $generateUniqueName = false,
		public readonly bool $shouldCheckUnifiedLink = false,
		public readonly ?array $previewFileData = null,
	)
	{
	}

	/**
	 * @throws ArgumentException
	 */
	protected function execute(): Result
	{
		return (new CreateByContentCommandHandler())($this);
	}
}
