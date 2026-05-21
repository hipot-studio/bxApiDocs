<?php

namespace Bitrix\Disk\Document\Flipchart\Enum;

enum BoardReadyStatus: string
{

	case IN_PROGRESS = 'IN_PROGRESS';
	case FAILED = 'FAILED';
	case DONE = 'DONE';
	/** Status ERROR means failed to obtain status from boards server */
	case ERROR = 'ERROR';

}