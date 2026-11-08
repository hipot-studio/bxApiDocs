<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Integration\Main\Web;

enum HostDiagnosis: string
{
	case CertificateMincifry = 'certificate_mincifry';
	case CertificateUntrusted = 'certificate_untrusted';
	case HostUnreachable = 'host_unreachable';
	case Unknown = 'unknown';
}
