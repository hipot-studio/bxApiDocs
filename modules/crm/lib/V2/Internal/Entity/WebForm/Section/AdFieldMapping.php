<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Section;

/**
 * @internal
 */
final readonly class AdFieldMapping
{
	public const FIELD_CRM_FIELD_NAME = 'crmFieldName';
	public const FIELD_ADS_FIELD_NAME = 'adsFieldName';

	public function __construct(
		public ?string $crmFieldName = null,
		public ?string $adsFieldName = null,
	)
	{
	}
}
