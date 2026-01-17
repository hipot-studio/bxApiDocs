<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare;

use CTaskTemplates;

class PrepareSiteId implements PrepareFieldInterface
{
	public function __invoke(array $fields, array $fullTemplateData): array
	{
		if (!isset($fields['SITE_ID']))
		{
			return $fields;
		}

		$siteId = (string)($fields['SITE_ID']);
		if (
			$siteId === ''
			|| $siteId === CTaskTemplates::CURRENT_SITE_ID
		)
		{
			$fields['SITE_ID'] = SITE_ID;
		}

		return $fields;
	}
}
