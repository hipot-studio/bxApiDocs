<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Tasks\Internals\Task\Template\TemplateTagTable;
use Bitrix\Tasks\V2\Internal\Entity\Template\TagCollection;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Template\TemplateTagMapper;

class TemplateTagRepository implements TemplateTagRepositoryInterface
{
	public function __construct(
		private readonly TemplateTagMapper $templateTagMapper,
	)
	{
	}

	public function getById(int $templateId): TagCollection
	{
		$tags = TemplateTagTable::query()
			->setSelect(['ID', 'TEMPLATE_ID', 'NAME', 'USER_ID'])
			->where('TEMPLATE_ID', $templateId)
			->fetchAll();

		return $this->templateTagMapper->mapToCollection($tags);
	}

	public function invalidate(int $taskId): void
	{

	}
}
