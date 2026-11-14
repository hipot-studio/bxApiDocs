<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Provider;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Context;

interface ActivityProviderTypeCatalogInterface
{
	/** @return ActivityProviderTypeDescriptor[] */
	public function getTimelineTypeDescriptors(): array;

	public function canExposeTimelineType(string $id, Context $context, ItemIdentifier $owner): bool;
}
