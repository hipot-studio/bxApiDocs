<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Entity\Template\TemplateCollection;

interface TemplateReadRepositoryInterface
{
	const DEFAULT_LIMIT = 50;

	public function getById(int $id, ?Select $select = null): ?Template;

	public function getAttachmentIds(int $templateId): array;

	public function getList(
		List\Select $select,
		List\Filter $filter,
		List\Order $order,
		int $limit = self::DEFAULT_LIMIT,
		int $offset = 0,
	): TemplateCollection;

	public function getCount(List\Filter $filter): int;
}
