<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\ExternalLink\CreateMailAttachmentLink;

enum CreateMailAttachmentLinkError: string
{
	case FeatureNotAvailable = 'FEATURE_NOT_AVAILABLE';
	case ObjectNotFound = 'OBJECT_NOT_FOUND';
	case ObjectOutOfScope = 'OBJECT_OUT_OF_SCOPE';
	case LinkCreationFailed = 'LINK_CREATION_FAILED';
}
