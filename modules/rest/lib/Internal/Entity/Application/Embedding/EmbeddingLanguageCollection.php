<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Application\Embedding;

use Bitrix\Main;

class EmbeddingLanguageCollection extends Main\Entity\EntityCollection
{
	protected static function getEntityClass(): string
	{
		return EmbeddingLanguage::class;
	}
}
