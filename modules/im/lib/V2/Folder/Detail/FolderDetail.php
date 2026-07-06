<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Detail;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatPopupItem;
use Bitrix\Im\V2\Entity\User\UserPopupItem;
use Bitrix\Im\V2\Folder\BaseFolder;
use Bitrix\Im\V2\Folder\Folder;
use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Im\V2\Rest\PopupDataAggregatable;
use Bitrix\Im\V2\Rest\RestConvertible;

final readonly class FolderDetail implements RestConvertible, PopupDataAggregatable
{
	/**
	 * @param Chat[] $chats
	 * @param int[]  $userIds
	 */
	public function __construct(
		public Folder $folder,
		public array $chats,
		public array $userIds,
	) {
	}

	public static function getRestEntityName(): string
	{
		return BaseFolder::getRestEntityName();
	}

	public function toRestFormat(array $option = []): array
	{
		return $this->folder->toRestFormat($option);
	}

	public function getPopupData(array $excludedList = []): PopupData
	{
		return new PopupData(
			[
				new ChatPopupItem($this->chats),
				new UserPopupItem($this->userIds),
			],
			$excludedList,
		);
	}
}
