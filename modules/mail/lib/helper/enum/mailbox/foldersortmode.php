<?php

namespace Bitrix\Mail\Helper\Enum\Mailbox;

enum FolderSortMode: string
{
	case Default = 'default';
	case AlphaAsc = 'alpha_asc';
	case AlphaDesc = 'alpha_desc';
}
