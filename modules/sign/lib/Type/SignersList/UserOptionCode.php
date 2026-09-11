<?php

namespace Bitrix\Sign\Type\SignersList;

/**
 * Personal per-row options of the signers list grid. A new personal flag gets a new code,
 * not a new table.
 */
enum UserOptionCode: int
{
	case Pinned = 1;
}
