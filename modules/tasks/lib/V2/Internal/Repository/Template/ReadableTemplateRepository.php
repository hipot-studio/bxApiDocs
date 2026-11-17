<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\V2\Internal\Repository\Template\List\Field;

class ReadableTemplateRepository implements ReadableTemplateRepositoryInterface
{
	public function __construct(
		private readonly TemplateReadRepositoryInterface $templateReadRepository,
	)
	{
	}

	public function filterReadableIds(array $templateIds, int $userId): array
	{
		Collection::normalizeArrayValuesByInt($templateIds, false);

		if (empty($templateIds) || $userId <= 0)
		{
			return [];
		}

		$templates = $this->templateReadRepository->getList(
			new List\Select([Field::Id]),
			new List\Filter([
				[Field::Id, 'in', $templateIds],
				// the legacy provider defaults to ZOMBIE = 'N', the list repository does not
				[Field::Zombie, '=', false],
				[Field::AccessUserId, '=', $userId],
			]),
			new List\Order(),
			count($templateIds),
		);

		$readableIds = [];
		foreach ($templates as $template)
		{
			$readableIds[] = (int)$template->id;
		}

		return $readableIds;
	}
}
