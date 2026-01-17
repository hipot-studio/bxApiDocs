<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Template;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Tasks\V2\Internal\Access\Service\TemplateRightService;
use Bitrix\Tasks\V2\Internal\Access\Task\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Entity\Group;
use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Access\Service\CrmAccessService;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Service\DiskArchiveLinkService;
use Bitrix\Tasks\V2\Internal\Integration\Socialnetwork\Service\GroupAccessService;
use Bitrix\Tasks\V2\Internal\Repository\Template\Select;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateReadRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Link\LinkService;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\TemplateParams;

class TemplateProvider
{
	private readonly TemplateRightService $templateRightService;
	private readonly LinkService $linkService;
	private readonly GroupAccessService $groupAccessService;
	private readonly CrmAccessService $crmAccessService;
	private readonly DiskArchiveLinkService $diskArchiveLinkService;
	private readonly TemplateReadRepositoryInterface $templateReadRepository;

	public function __construct()
	{
		$this->templateRightService = ServiceLocator::getInstance()->get(TemplateRightService::class);
		$this->linkService = ServiceLocator::getInstance()->get(LinkService::class);
		$this->groupAccessService = ServiceLocator::getInstance()->get(GroupAccessService::class);
		$this->crmAccessService = ServiceLocator::getInstance()->get(CrmAccessService::class);
		$this->diskArchiveLinkService = ServiceLocator::getInstance()->get(DiskArchiveLinkService::class);
		$this->templateReadRepository = ServiceLocator::getInstance()->get(TemplateReadRepositoryInterface::class);
	}
	
	public function get(TemplateParams $templateParams): ?Template
	{
		if (
			$templateParams->checkTemplateAccess
			&& !$this->templateRightService->canView(
				$templateParams->userId,
				$templateParams->templateId
			)
		)
		{
			return null;
		}

		$select = new Select(
			group: $templateParams->group,
			members: $templateParams->members,
			checkLists: $templateParams->checkLists,
			crm: $templateParams->crm,
			tags: $templateParams->tags,
			subTemplates: $templateParams->subTemplates,
			userFields: $templateParams->userFields,
			relatedTasks: $templateParams->relatedTasks,
			permissions: $templateParams->permissions,
			parent: $templateParams->parent,
		);

		$template = $this->templateReadRepository->getById(
			id: $templateParams->templateId,
			select: $select,
		);

		if ($template === null)
		{
			return null;
		}

		$modifiers = [
			fn (): array => $this->prepareGroup($templateParams, $template),
			fn (): array => $this->prepareCrmItems($templateParams, $template),
			fn (): array => $this->prepareRights($templateParams, $template),
			fn (): array => $this->prepareLink($templateParams, $template),
			fn (): array => $this->prepareArchiveLink($template),
		];

		$data = [];
		foreach ($modifiers as $modifier)
		{
			$data = [...$data, ...$modifier()];
		}

		$template = $template->cloneWith($data);

		return $template;
	}

	protected function prepareGroup(TemplateParams $templateParams, Template $template): array
	{
		if (!$templateParams->group || !$templateParams->checkGroupAccess || !$template->group)
		{
			return [];
		}

		if ($this->groupAccessService->canViewGroup($templateParams->userId, $template->group))
		{
			return [];
		}

		// only allowed data
		$group = new Group(
			id: $template->group->getId(),
			name: $template->group->name,
			image: $template->group->image,
			type: $template->group->type,
		);

		return ['group' => $group];
	}

	protected function prepareCrmItems(TemplateParams $templateParams, Template $template): array
	{
		if (!$template->crmItemIds || !$templateParams->checkCrmAccess)
		{
			return [];
		}

		$crmItemIds = $this->crmAccessService->filterCrmItemsWithAccess($template->crmItemIds, $templateParams->userId);

		return ['crmItemIds' => $crmItemIds];
	}

	protected function prepareRights(TemplateParams $templateParams, Template $template): array
	{
		$rights = $this->templateRightService->get(
			ActionDictionary::TEMPLATE_ACTIONS,
			$template->getId(),
			$templateParams->userId,
		);

		return ['rights' => $rights];
	}

	protected function prepareLink(TemplateParams $templateParams, Template $template): array
	{
		$link = $this->linkService->get($template, $templateParams->userId);

		return ['link' => $link];
	}

	protected function prepareArchiveLink(Template $template): array
	{
		$archiveLink = $this->diskArchiveLinkService->getByTemplateId($template->getId());

		return ['archiveLink' => $archiveLink];
	}
}
