<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MigrationNotice;

/**
 * The messages of the migration of mail to Bitrix24 Mail (DTO-01).
 *
 * The value is what reaches the web and the mobile client.
 */
enum NoticeCode: string
{
	case None = 'none';
	case Announcement = 'ann_1';
	case MigrationVariantA = 'mig_a';
}
