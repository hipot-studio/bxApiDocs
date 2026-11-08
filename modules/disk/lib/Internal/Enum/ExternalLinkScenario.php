<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Enum;

/**
 * Closed registry of external link scenarios. A link without a scenario is a regular manual link.
 */
enum ExternalLinkScenario: string
{
	case MailAttachments = 'mail_attachments';
}
