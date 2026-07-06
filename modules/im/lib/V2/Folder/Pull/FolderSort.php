<?php
declare(strict_types = 1);

namespace Bitrix\Im\V2\Folder\Pull;

use Bitrix\Im\V2\Pull\EventType;

class FolderSort extends BaseFolderEvent
{
	/**
	 * @param int[] $folderIds full ordered list of visible folder ids
	 */
	public function __construct(
		private readonly array $folderIds,
		int $userId,
	)
	{
		parent::__construct($userId);
	}

	protected function getType(): EventType
	{
		return EventType::FolderSort;
	}

	protected function getBasePullParamsInternal(): array
	{
		return [
			'folderIds' => array_values(array_map('intval', $this->folderIds)),
		];
	}
}
