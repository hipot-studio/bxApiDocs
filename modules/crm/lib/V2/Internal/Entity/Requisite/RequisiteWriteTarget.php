<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Requisite;

/**
 * What a write of an existing requisite has to know about the row before it can decide anything: the
 * owner the row belongs to and the preset its fields are validated against.
 *
 * Three values rather than the row itself, and the point of the type is to keep it at three. Every
 * operation of the write reads exactly these - the owner to ask the permission over it and to address
 * the effects afterwards, the preset to validate the fields against when the call does not name another
 * one - and the identifier of the row the caller already holds.
 *
 * Reading the row whole instead costs about ten times as much, and next to none of it in the storage: a
 * query of the ORM over the seventy-seven columns of a requisite is assembled field by field, and that
 * assembly, not the row, is the price - eleven milliseconds against two or three for these three
 * columns, on a row the database answers just as quickly either way. So a write that turns out to need
 * one more value gets one more field here, as a decision, rather than a read of everything on the
 * grounds that it is one row anyway.
 *
 * @internal
 */
final readonly class RequisiteWriteTarget
{
	public function __construct(
		public int $ownerTypeId,
		public int $ownerId,
		public int $presetId,
	)
	{
	}
}
