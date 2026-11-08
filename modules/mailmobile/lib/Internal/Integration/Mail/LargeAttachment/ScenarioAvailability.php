<?php

declare(strict_types=1);

namespace Bitrix\MailMobile\Internal\Integration\Mail\LargeAttachment;

use Bitrix\MailMobile\Internal\Integration\Disk\AttachmentPlacement;
use Bitrix\MailMobile\Internal\Integration\Disk\AttachmentPlacementFactory;

/**
 * The one answer about the large attachment scenario of mobile mail. It has two halves, and both of them
 * have to say yes: the Disk half (the rollout flag of this module, the modules the placement needs and the
 * personal Disk the attachment would go into) and the mail half (a mail module that carries the scenario,
 * with its own disk upload option switched on).
 *
 * The composite used to live in the compose preload alone, so the receiver of the files answered by the
 * rollout flag of mobile mail only. On a portal whose mail module could not send a set the form was told
 * the scenario was off while every attachment - a small one included - was still stored on the Disk of the
 * user and reported back as an object of it, which the compatible send path has no way to use. Anyone who
 * creates a new placement asks this class now, so the two answers cannot disagree about a portal or about
 * a person.
 */
final class ScenarioAvailability
{
	public static function isEnabled(): bool
	{
		return self::isPlacementAvailable() && self::isSendAvailable();
	}

	/**
	 * The Disk half: the rollout flag of mobile mail, the modules an attachment is stored through, and the
	 * personal Disk of the person the attachment would be stored in.
	 *
	 * The last term is about the person and not about the portal. There are people who have no Disk of their
	 * own - someone added while the socialnetwork module was off never got a storage, a storage may fail to
	 * appear, and the right to add to one's own root may be taken away - and telling them the scenario was on
	 * took away every attachment they had: the receiver of the files declared the ceiling of the Disk and
	 * then refused a file of a hundred bytes for want of a permission. This is the same shape of mistake the
	 * mail half was added for, one floor down: whoever cannot store a file keeps the path of before the move.
	 */
	public static function isPlacementAvailable(): bool
	{
		return AttachmentPlacement::isAvailable() && AttachmentPlacementFactory::isOwnStorageWritable();
	}

	/**
	 * The mail half: a mail module that carries the scenario, and the portal option it answers its own
	 * rollout of it by. Storing an attachment in the Disk is pointless while the send cannot convert a set.
	 */
	public static function isSendAvailable(): bool
	{
		return SendPreparation::isAvailable();
	}
}
