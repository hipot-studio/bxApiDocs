<?php
declare(strict_types = 1);

namespace Bitrix\Im\V2\Folder\Pull;

use Bitrix\Im\V2\Folder\Folder;
use Bitrix\Im\V2\Pull\EventType;

class FolderUpdate extends BaseFolderEvent
{
	/**
	 * @param array $restOption options of {@see Folder::toRestFormat}; a sender pushing several folders
	 *        of one collection passes the dialog ids resolved for the whole of it
	 */
	public function __construct(
		private readonly Folder $folder,
		int $userId,
		private readonly array $restOption = [],
	)
	{
		parent::__construct($userId);
	}

	protected function getType(): EventType
	{
		return EventType::FolderUpdate;
	}

	protected function getBasePullParamsInternal(): array
	{
		return ['folder' => $this->folder->toRestFormat($this->restOption) ?? []];
	}
}
