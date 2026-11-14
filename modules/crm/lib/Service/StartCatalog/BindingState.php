<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\StartCatalog;

/**
 * What became of the CRM form chosen in the settings of a smart process.
 *
 * Only a living form is opened anywhere; the other states tell the settings what to warn about.
 * When several apply, the first of deleted, switched off and unsuitable is reported.
 */
enum BindingState: string
{
	case None = 'none';
	case Live = 'live';
	case Deleted = 'deleted';
	case Inactive = 'inactive';
	case Unsuitable = 'unsuitable';
}
