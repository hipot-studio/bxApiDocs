<?php

declare(strict_types=1);

namespace Bitrix\Landing\Markup\Submission;

/**
 * Where a markup package comes from. The source tells the transport and the target apart;
 * the assembly, the validation and the scenario are the same for every source.
 */
enum PackageSource: string
{
	case Chat = 'chat';
	case Dev = 'dev';
	case Rest = 'rest';
}
