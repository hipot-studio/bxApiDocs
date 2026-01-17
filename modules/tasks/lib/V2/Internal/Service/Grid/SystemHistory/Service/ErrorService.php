<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\SystemHistory\Service;

use Bitrix\Tasks\V2\Internal\Integration\Ui\Article\ArticleService;
use Bitrix\Tasks\V2\Internal\Service\SystemHistoryLog\ErrorCodeDictionary;

class ErrorService
{
	public function __construct(
		private readonly ArticleService $articleService,
	)
	{

	}

	public function fillErrors(?array $errors): ?array
	{
		if (empty($errors))
		{
			return $errors;
		}

		return array_map(
			function (array $error): array {
				if ((string)($error['CODE'] ?? '') !== ErrorCodeDictionary::ACCESS_DENIED)
				{
					return $error;
				}

				$link = $this->articleService->getTaskAccessRightsArticleLink();

				if ($link !== null)
				{
					$error['LINK'] = $link;
				}

				return $error;
			},
			$errors,
		);
	}
}
