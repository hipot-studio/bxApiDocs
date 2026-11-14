<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UploadCleanup;

enum MarkerStatus: string
{
	case Pending = 'pending';
	case Publishing = 'publishing';
	case Deleting = 'deleting';
	case PublicationDeleting = 'publish_delete';
	case Revoked = 'revoked';
	case Consumed = 'consumed';
}
