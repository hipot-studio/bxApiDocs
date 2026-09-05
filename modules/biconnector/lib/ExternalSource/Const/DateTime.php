<?php

namespace Bitrix\BIConnector\ExternalSource\Const;

enum DateTime: string
{
	/**
	 * Predefined format offered along with the enum cases, but kept out of them: unlike the cases,
	 * it has its own UI title and is not built from the ISO 8601 notation.
	 */
	public const ISO_8601 = 'Y-m-d\TH:i:s';

	case Ymd_dot_His_colon = 'Y.m.d H:i:s';
	case Ydm_dot_His_colon = 'Y.d.m H:i:s';
	case dmY_dot_His_colon = 'd.m.Y H:i:s';
	case mdY_dot_His_colon = 'm.d.Y H:i:s';
	case Ymd_dash_His_colon = 'Y-m-d H:i:s';
	case Ydm_dash_His_colon = 'Y-d-m H:i:s';
	case dmY_dash_His_colon = 'd-m-Y H:i:s';
	case mdY_dash_His_colon = 'm-d-Y H:i:s';
	case Ymd_slash_His_colon = 'Y/m/d H:i:s';
	case Ydm_slash_His_colon = 'Y/d/m H:i:s';
	case dmY_slash_His_colon = 'd/m/Y H:i:s';
	case mdY_slash_His_colon = 'm/d/Y H:i:s';
}
