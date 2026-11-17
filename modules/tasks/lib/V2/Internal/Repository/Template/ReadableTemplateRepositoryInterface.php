<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

interface ReadableTemplateRepositoryInterface
{
	/**
	 * @param int[] $templateIds
	 * @return int[] ids the user is allowed to read, in no guaranteed order
	 */
	public function filterReadableIds(array $templateIds, int $userId): array;
}
