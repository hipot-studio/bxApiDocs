<?php

namespace Bitrix\StaffTrack\Internal\Integration\Im\Blocks;

enum CheckInBlockType
{
	case Title;
	case Text;
	case SpaceDivider;
	case Gallery;
	case File;
	case LineDivider;
	case Map;
}
