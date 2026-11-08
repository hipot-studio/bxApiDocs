<?php

namespace Bitrix\Sign\Item\Document;

use Bitrix\Sign\Contract;
use Bitrix\Sign\Type\Document\CreationSourceType;

/**
 * Origin of the document: what created it, within which act, from which entity.
 * Only the type reaches the signing service, the rest stays on the portal.
 */
final class CreationSource implements Contract\Item
{
	public function __construct(
		public CreationSourceType $type,
		public ?string $id = null,
		public ?int $entityTypeId = null,
		public ?int $entityId = null,
		/** @var array<string, int|string> keyed by CreationSourceMetaKey values */
		public array $specifics = [],
	)
	{
	}
}
