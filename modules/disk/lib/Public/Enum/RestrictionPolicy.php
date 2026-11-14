<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Enum;

/**
 * A restriction the portal applies to Disk. Both policies are switched on by one feature flag,
 * while the tariff and the edition are asked about per policy, so the answer stays per policy.
 *
 * The policy is named by a public contract of the module, so the type lives in the public part of
 * disk beside the state it is answered with.
 */
enum RestrictionPolicy: string
{
	case Formats = 'formats';
	case Quota = 'quota';
}
