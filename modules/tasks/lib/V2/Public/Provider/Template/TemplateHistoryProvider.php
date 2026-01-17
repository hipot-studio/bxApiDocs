<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Template;

use Bitrix\Tasks\V2\Internal\Access\Service\TemplateRightService;
use Bitrix\Tasks\V2\Internal\Entity\SystemHistoryLogCollection;
use Bitrix\Tasks\V2\Internal\Repository\SystemHistoryRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Grid\SystemHistory\Service\EnrichService;
use Bitrix\Tasks\V2\Public\Provider\Template\Params\TemplateHistoryCountParams;
use Bitrix\Tasks\V2\Public\Provider\Template\Params\TemplateHistoryParams;

class TemplateHistoryProvider
{
	public function __construct(
		private readonly SystemHistoryRepositoryInterface $systemHistoryRepository,
		private readonly TemplateRightService $templateRightService,
		private readonly EnrichService $enrichService,
	)
	{

	}

	public function tail(TemplateHistoryParams $templateHistoryParams): SystemHistoryLogCollection
	{
		if (
			$templateHistoryParams->checkAccess
			&& !$this->templateRightService->canView(
				userId: $templateHistoryParams->userId,
				templateId: $templateHistoryParams->templateId,
			)
		)
		{
			return new SystemHistoryLogCollection();
		}

		$systemHistoryLocCollection = $this->systemHistoryRepository->tail(
			templateId: $templateHistoryParams->templateId,
			offset: $templateHistoryParams->getOffset(),
			limit: $templateHistoryParams->getLimit(),
		);

		return $this->enrichService->enrich(
			systemHistoryLogCollection: $systemHistoryLocCollection,
			userId: $templateHistoryParams->userId,
		);
	}

	public function count(TemplateHistoryCountParams $templateHistoryCountParams): ?int
	{
		if (
			$templateHistoryCountParams->checkAccess
			&& !$this->templateRightService->canView(
				userId: $templateHistoryCountParams->userId,
				templateId: $templateHistoryCountParams->templateId,
			)
		)
		{
			return null;
		}

		return $this->systemHistoryRepository->count($templateHistoryCountParams->templateId);
	}
}
