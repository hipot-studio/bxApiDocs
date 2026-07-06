<?php
declare(strict_types = 1);

namespace Bitrix\Im\V2\Folder\Pull;

use Bitrix\Im\V2\Folder\Folder;
use Bitrix\Im\V2\Pull\EventType;

class FolderCreate extends BaseFolderEvent
{
	public function __construct(
		private readonly Folder $folder,
		int $userId,
	)
	{
		parent::__construct($userId);
	}

	protected function getType(): EventType
	{
		return EventType::FolderCreate;
	}

	protected function getBasePullParamsInternal(): array
	{
		return ['folder' => $this->folder->toRestFormat() ?? []];
	}
}
