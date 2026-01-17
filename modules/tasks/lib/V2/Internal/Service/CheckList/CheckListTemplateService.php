<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\CheckList;

use Bitrix\Tasks\CheckList\Exception\CheckListException;
use Bitrix\Tasks\CheckList\Template\TemplateCheckListFacade;
use Bitrix\Tasks\Control\Exception\TemplateNotFoundException;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\CheckListMapper;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateReadRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\CheckList\Prepare\Save\CheckListEntityFieldService;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\Trait\ApplicationErrorTrait;
use Bitrix\Tasks\V2\Internal\Service\UpdateTemplateService;
use Bitrix\Tasks\V2\Public\Provider\CheckListProvider;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;

class CheckListTemplateService extends BaseCheckListService
{
	use ApplicationErrorTrait;

	public function __construct(
		private readonly TemplateReadRepositoryInterface $templateReadRepository,
		private readonly CheckListEntityFieldService $checkListEntityFieldService,
		private readonly TemplateCheckListFacade $checkListFacade,
		private readonly CheckListMapper $checkListMapper,
		private readonly UpdateTemplateService $updateTemplateService,
		CheckListProvider $checkListProvider,
		CheckListFacadeResolver $checkListFacadeResolver,
		Logger $logger,
	)
	{
		parent::__construct($checkListProvider, $checkListFacadeResolver, $logger);
	}

	public function complete(array $ids, int $userId): Entity\CheckList
	{
		[$newCheckList] = $this->changeItemsStatus(ids: $ids, userId: $userId, isComplete: true);

		return $newCheckList;
	}

	public function renew(array $ids, int $userId): Entity\CheckList
	{
		[$newCheckList] = $this->changeItemsStatus(ids: $ids, userId: $userId, isComplete: false);

		return $newCheckList;
	}

	public function save(array $checkLists, int $templateId, int $userId): Entity\Template
	{
		$template = $this->templateReadRepository->getById($templateId);

		if ($template === null)
		{
			throw new TemplateNotFoundException();
		}

		$checkLists = $this->checkListEntityFieldService->prepare($checkLists);

		$checkListsToUpdate = $this->checkListMapper->mapToNodes($checkLists)->toArray();

		$nodes = $this->checkListProvider->merge(
			entityId: $templateId,
			userId: $userId,
			checkLists: $checkListsToUpdate,
			type: Entity\CheckList\Type::Template,
		);

		if ($nodes === null)
		{
			throw new CheckListException($this->getApplicationError());
		}

		$template = new Entity\Template(
			id: $templateId,
			checklist: $this->checkListMapper->mapToArray($nodes),
			accomplices: UserCollection::mapFromIds($nodes->getAccomplices()),
			auditors: UserCollection::mapFromIds($nodes->getAuditors()),
		);

		$config = new UpdateConfig(userId: $userId);

		$this->updateTemplateService->update(template: $template, config: $config);

		return $template;
	}

	protected function getEntity(int $entityId): ?Template
	{
		return $this->templateReadRepository->getById($entityId);
	}

	protected function getEntityType(): Entity\CheckList\Type
	{
		return Entity\CheckList\Type::Template;
	}
}
