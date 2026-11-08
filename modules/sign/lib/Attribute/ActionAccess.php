<?php

namespace Bitrix\Sign\Attribute;

use Attribute;

#[Attribute(flags: Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class ActionAccess
{
	public function __construct(
		public string $permission,
		/** @var null|\Bitrix\Sign\Type\Access\AccessibleItemType::* $itemType */
		public ?string $itemType = null,
		public ?string $itemIdOrUidRequestKey = null,
		/**
		 * Entity identified by the request key when it is not the access item itself, e.g. a
		 * member uid for an action authorized by the permission on the member's document.
		 *
		 * @var null|\Bitrix\Sign\Type\Access\ItemIdentifierType::* $identifiedBy
		 */
		public ?string $identifiedBy = null,
	)
	{}
}
