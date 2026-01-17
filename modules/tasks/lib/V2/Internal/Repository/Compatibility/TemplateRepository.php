<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Compatibility;

use Bitrix\Tasks\Control\Exception\TemplateNotFoundException;
use CTaskTemplates;

class TemplateRepository
{
	public function getTemplateData(int $templateId): array
	{
		$template = CTaskTemplates::GetByID($templateId)->Fetch();

		if (empty($template))
		{
			throw new TemplateNotFoundException();
		}

		$template['ID'] = (int)$template['ID'];

		return $template;
	}
}
