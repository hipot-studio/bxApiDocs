<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync;

use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Internal sharing of an object: explicit rights granted beyond the base rights of the storage.
 *
 * Property order is the order of the published answer.
 */
final class SharingDto extends Dto
{
	/** The object itself carries explicit positive rights; the root of the storage does not count. */
	public bool $enabled;

	/** An ancestor below the root carries such rights, whether the reader sees it or not. */
	public bool $inherited;

	/**
	 * Recipients of the rights of the object itself. Absent when the reader is not shown them; an
	 * empty list means there is nobody.
	 *
	 * @var SharingTargetDto[]
	 */
	#[ElementType(SharingTargetDto::class)]
	public array $targets;
}
