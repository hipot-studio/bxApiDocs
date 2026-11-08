<?php
declare(strict_types = 1);

namespace Bitrix\Im\V2\Folder\Pull;

use Bitrix\Im\V2\Pull\EventType;

class FolderSort extends BaseFolderEvent
{
	/**
	 * @param int[] $folderIds canonical server order of every top-level folder of the user,
	 *                         hidden and unavailable ones included: a client keeps what it knows
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
