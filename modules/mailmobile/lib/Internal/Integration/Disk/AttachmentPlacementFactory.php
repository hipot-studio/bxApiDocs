<?php

declare(strict_types=1);

namespace Bitrix\MailMobile\Internal\Integration\Disk;

use Bitrix\Disk\Driver;
use Bitrix\MailMobile\Internal\Integration\Mail\LargeAttachment\ScenarioAvailability;
use Bitrix\Main\Engine\CurrentUser;

/**
 * Where the placement of attachments is assembled. The accessor of the disk module is called here and only
 * here, so that the adapter itself works with what it was given and stays answerable in a test.
 */
final class AttachmentPlacementFactory
{
	private static ?AttachmentPlacement $placement = null;

	/**
	 * The placement a new attachment is stored through, or null while the scenario is not on for this
	 * portal. The null is what every entry point of the module checks: with no placement attaching works
	 * exactly as it did before the move.
	 *
	 * The whole gate is asked, not the rollout flag alone: a portal whose mail module cannot send a set is
	 * told the scenario is off, so an attachment it stored on the Disk would be an identifier the compatible
	 * send path cannot use, and the person would need a reachable Disk to attach even a small file.
	 */
	public static function createForNewAttachments(): ?AttachmentPlacement
	{
		if (!ScenarioAvailability::isEnabled())
		{
			return null;
		}

		return self::assemble();
	}

	/**
	 * The placement an object already stored in the Disk is read through, or null while the Disk cannot be
	 * talked to at all. Reading is a separate question from creating: a form opened before the rollback
	 * still holds identifiers of files that exist and the person may read, and a restored draft holds them
	 * too. Refusing those would break the send of every form and draft opened earlier, so the gate of the
	 * scenario is not asked here - only whether the Disk is reachable. What may be read is then decided by
	 * the permission model of the Disk, object by object.
	 */
	public static function createForExistingObjects(): ?AttachmentPlacement
	{
		if (!AttachmentPlacement::isReachable())
		{
			return null;
		}

		return self::assemble();
	}

	/**
	 * Whether the personal Disk of the person can take a new attachment. It is one of the terms of the gate
	 * of the scenario, so it is answered here and not behind the gate: the accessor of the disk module lives
	 * in this factory, and asking the gate for a placement to answer the gate with would ask itself.
	 */
	public static function isOwnStorageWritable(): bool
	{
		if (!AttachmentPlacement::isReachable())
		{
			return false;
		}

		return self::assemble()->isTargetWritable((int)CurrentUser::get()?->getId());
	}

	/**
	 * One adapter for the request, over the one driver of the disk module: the answer about the personal Disk
	 * of the person is asked on every chunk of an upload, and the adapter that gave it remembers it. Nothing
	 * is written on the strength of a remembered answer - the write is guarded by the placement itself.
	 */
	private static function assemble(): AttachmentPlacement
	{
		return self::$placement ??= new AttachmentPlacement(Driver::getInstance());
	}
}
