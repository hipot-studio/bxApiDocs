<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder;

enum Channel: string
{
	case Mcp = 'mcp';
	case Rest = 'rest';
}
