<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare;

class PrepareTags implements PrepareFieldInterface
{
	public function __invoke(array $fields, array $fullTemplateDate): array
	{
		if (
			!isset($fields['TAGS'])
			&& !isset($fields['SE_TAG'])
		)
		{
			return $fields;
		}

		$tags = [];

		if (isset($fields['TAGS']) && is_array($fields['TAGS']))
		{
			$tags = $fields['TAGS'];
		}

		if (is_array($fields['SE_TAG'] ?? null))
		{
			foreach ($fields['SE_TAG'] as $tag)
			{
				if (empty($tag))
				{
					continue;
				}

				if (is_string($tag))
				{
					$tags[] = $tag;
				}
				else if (is_array($tag) && isset($tag['NAME']))
				{
					$tags[] = $tag['NAME'];
				}
			}
		}

		$fields['TAGS'] = array_unique(array_filter($tags));

		return $fields;
	}
}
