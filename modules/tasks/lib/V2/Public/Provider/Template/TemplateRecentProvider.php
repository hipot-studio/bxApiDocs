<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Template;

use Bitrix\Tasks\V2\Internal\Access\Task\ActionDictionary;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Access\Service\TemplateRightService;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateRecentRepository;

class TemplateRecentProvider
{
	private readonly TemplateRecentRepository $recentRepository;
	private readonly TemplateRightService $templateRightService;

	public function __construct()
	{
		$this->recentRepository = Container::getInstance()->get(TemplateRecentRepository::class);
		$this->templateRightService = Container::getInstance()->get(TemplateRightService::class);
	}

	public function getRecentIds(int $userId): array
	{
		$recentIds = $this->recentRepository->get($userId);

		if (empty($recentIds))
		{
			return [];
		}

		$rights = $this->templateRightService->getTemplateRightsBatch(
			userId: $userId,
			templateIds: $recentIds,
			rules: ['read' => ActionDictionary::TEMPLATE_ACTIONS['read']],
		);

		return array_values(array_filter($recentIds, static fn(int $id) => $rights[$id]['read'] ?? false));
	}
}
