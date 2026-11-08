<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MigrationNotice;

/**
 * The messages of the migration of mail to Bitrix24 Mail (DTO-01).
 *
 * The value is what reaches the web and the mobile client, and the same value the mailbox property
 * store keeps as the shown popup variant, so the rules can tell one migration popup from the other.
 */
enum NoticeCode: string
{
	case None = 'none';
	case Announcement = 'ann_1';
	case MigrationVariantA = 'mig_a';
	case MigrationVariantB = 'mig_b';
	case Banner = 'ban_1';
	case BrokenMailboxOffer = 'err_1_offer';
}
