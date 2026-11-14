<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Enum;

enum BoardIndexErrorCategory: string
{
	case Transport = 'transport';
	case Timeout = 'timeout';
	case Http = 'http';
	case InvalidJson = 'invalid_json';
	case SigningEnvelopeInvalid = 'signing_envelope_invalid';
	case ElementsEnvelopeInvalid = 'elements_envelope_invalid';
	case ResponseTooLarge = 'response_too_large';
	case ProfileUnresolved = 'profile_unresolved';
	case ProfileUnavailable = 'profile_unavailable';
	case ProxyNotRegistered = 'proxy_not_registered';
	case ClientRejected = 'client_rejected';
}
