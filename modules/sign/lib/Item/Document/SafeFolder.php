<?php

namespace Bitrix\Sign\Item\Document;

use Bitrix\Sign\Contract;
use Bitrix\Sign\Item\TrackableItemTrait;
use Bitrix\Sign\Type;

/**
 * Company safe folder domain item. Owner is the creator; used by the access layer to gate
 * folder and (through the folder) document access (NORMATIVE ALG-01).
 */
class SafeFolder implements Contract\Item, Contract\Item\ItemWithOwner, Contract\Item\TrackableItem
{
	use TrackableItemTrait;

	public function __construct(
		public string $title,
		public int $createdById,
		public ?int $id = null,
		public ?int $modifiedById = null,
		public ?Type\DateTime $dateModify = null,
		public Type\DateTime $dateCreate = new Type\DateTime(),
	)
	{
		$this->initOriginal();
	}

	public function getId(): int
	{
		return $this->id ?? 0;
	}

	public function getOwnerId(): int
	{
		return $this->createdById;
	}
}
