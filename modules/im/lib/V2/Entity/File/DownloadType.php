<?php

namespace Bitrix\Im\V2\Entity\File;

/**
 * Mode of the direct file download. The size of a preview is chosen by the server, so the enum
 * carries the mode only and never a size.
 */
enum DownloadType: string
{
	case Full = 'full';

	case Preview = 'preview';
}
