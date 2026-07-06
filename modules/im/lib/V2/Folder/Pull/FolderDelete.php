<?php
declare(strict_types = 1);

namespace Bitrix\Im\V2\Folder\Pull;

use Bitrix\Im\V2\Pull\EventType;

class FolderDelete extends BaseFolderEvent
{
	public function __construct(
		private readonly int $folderId,
		int $userId,
	)
	{
		parent::__construct($userId);
	}

	protected function getType(): EventType
	{
		return EventType::FolderDelete;
	}

	protected function getBasePullParamsInternal(): array
	{
		return [
			'folderId' => $this->folderId,
		];
	}
}
