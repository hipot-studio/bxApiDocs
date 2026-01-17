<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare;

class PrepareTags implements PrepareFieldInterface
{
	public function __invoke(array $fields): array
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

		$templateTags = [];
		foreach ($tags as $tag)
		{
			if (empty($tag))
			{
				continue;
			}

			if (is_string($tag))
			{
				$templateTags[] = $tag;
			}
			elseif (is_array($tag) && isset($tag['NAME']))
			{
				$templateTags[] = $tag['NAME'];
			}
		}

		$fields['TAGS'] = array_unique(array_filter($templateTags));

		return $fields;
	}
}
