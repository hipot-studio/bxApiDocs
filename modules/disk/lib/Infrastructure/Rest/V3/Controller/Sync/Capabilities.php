<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Controller\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\CapabilitiesDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync\CapabilitiesRequest;
use Bitrix\Disk\Infrastructure\Rest\V3\Response\Sync\CapabilitiesResponse;
use Bitrix\Disk\Internal\Service\Sync\CapabilitiesProvider;
use Bitrix\Rest\V3\Attribute\DtoType;

/**
 * disk.sync.capabilities.get - what this portal can do, asked before anything else.
 *
 * The only method of the contour that reads nothing a user owns: no access codes are refreshed and
 * no snapshot is compared, because nothing here depends on who may see what.
 */
#[DtoType(CapabilitiesDto::class)]
final class Capabilities extends BaseSyncController
{
	/**
	 * The request carries no parameters and is taken for the sake of being built: constructing it
	 * is what refuses a parameter the schema does not declare.
	 */
	public function getAction(CapabilitiesRequest $request): CapabilitiesResponse
	{
		return new CapabilitiesResponse((new CapabilitiesProvider())->get());
	}
}
