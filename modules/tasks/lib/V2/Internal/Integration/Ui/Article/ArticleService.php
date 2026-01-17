<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Ui\Article;

use Bitrix\Main\Loader;
use Bitrix\UI\Util;

class ArticleService
{
	public function getTaskAccessRightsArticleLink(): ?string
	{
		if (!Loader::includeModule('ui'))
		{
			return null;
		}

		return Util::getArticleUrlByCode(ArticleDictionary::TASK_ACCESS_RIGHTS_ARTICLE_CODE);
	}
}
