<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare;

class PrepareDependencies implements PrepareFieldInterface
{
	public function __invoke(array $fields, array $fullTemplateData): array
	{
		if (!isset($fields['DEPENDS_ON']))
		{
			return $fields;
		}

		if (!is_array($fields['DEPENDS_ON']))
		{
			$fields['DEPENDS_ON'] = [];
		}

		$dependsOn = [];
		foreach ($fields['DEPENDS_ON'] as $dependId)
		{
			$dependId = (int)$dependId;
			if ($dependId > 0)
			{
				$dependsOn[$dependId] = $dependId;
			}
		}

		$fields['DEPENDS_ON'] = $dependsOn;

		return $fields;
	}
}
