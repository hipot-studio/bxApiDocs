<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Repository\Template\RelatedTaskTemplateRepositoryInterface;

class RelatedTaskTemplateService
{
	public function __construct(
		private readonly RelatedTaskTemplateRepositoryInterface $relatedTaskTemplateRepository,
	)
	{
	}

	public function add(int $templateId, array $relatedTaskIds): Template
	{
		Collection::normalizeArrayValuesByInt($relatedTaskIds, false);
		if (empty($relatedTaskIds))
		{
			throw new ArgumentException('Empty related task IDs array provided.', 'relatedTaskIds');
		}

		if (in_array($templateId, $relatedTaskIds, true))
		{
			throw new ArgumentException('Task cannot depend on itself.');
		}

		$this->relatedTaskTemplateRepository->save($templateId, $relatedTaskIds);

		$current = $this->relatedTaskTemplateRepository->getRelatedTaskIds($templateId);

		return new Template(
			id: $templateId,
			dependsOn: $current,
		);
	}

	public function delete(int $templateId, array $relatedTaskIds): Template
	{
		Collection::normalizeArrayValuesByInt($relatedTaskIds, false);
		if (empty($relatedTaskIds))
		{
			throw new ArgumentException('Empty related task IDs array provided.', 'relatedTaskIds');
		}

		$this->relatedTaskTemplateRepository->deleteByRelatedTaskIds($templateId, $relatedTaskIds);

		$current = $this->relatedTaskTemplateRepository->getRelatedTaskIds($templateId);

		return new Template(
			id: $templateId,
			dependsOn: $current,
		);
	}
}
