<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Template\Relation;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Provider\Params\SelectInterface;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Access\Service\TemplateRightService;
use Bitrix\Tasks\V2\Internal\Access\Task\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Access\Template\Permission;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Template\TemplateParentService;
use Bitrix\Tasks\V2\Public\Command\Template\Relation\DeleteBaseRelationCommand;
use Bitrix\Tasks\V2\Public\Command\Template\Relation\SetBaseRelationCommand;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\Relation\RelationTemplateParams;
use Bitrix\Tasks\V2\Public\Provider\Template\Relation\SubTemplateProvider;
use Bitrix\Tasks\Validation\Rule\Count;

class Child extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Template.Relation.Child.list
	 */
	#[CloseSession]
	public function listAction(
		#[Permission\Read]
		Entity\Template $template,
		PageNavigation $pageNavigation,
		SubTemplateProvider $subTemplateProvider,
		SelectInterface|null $relationTemplateSelect = null,
		bool $withIds = true,
	): array
	{
		$params = new RelationTemplateParams(
			userId: $this->userId,
			templateId: (int)$template->id,
			pager: Pager::buildFromPageNavigation($pageNavigation),
			select: $relationTemplateSelect,
		);

		$response = [
			'templates' => $subTemplateProvider->getTemplates($params),
		];

		if ($withIds)
		{
			$response['ids'] = $subTemplateProvider->getTemplateIds($params);
		}

		return $response;
	}

	/**
	 * @ajaxAction tasks.V2.Template.Relation.Child.listByIds
	 */
	#[CloseSession]
	public function listByIdsAction(
		#[ElementsType(typeEnum: Type::Numeric)]
		array $templateIds,
		SubTemplateProvider $subTemplateProvider,
	): array
	{
		return [
			'templates' => $subTemplateProvider->getTemplatesByIds($templateIds, $this->userId),
		];
	}

	/**
	 * @ajaxAction tasks.V2.Template.Relation.Child.add
	 */
	public function addAction(
		#[Permission\Read]
		Entity\Template $template,
		#[Count(min: 1, max: 20)]
		Entity\Template\TemplateCollection $templates,
		TemplateRightService $templateRightService,
		TemplateParentService $templateParentService,
		bool $noOverride = false,
	): ?array
	{
		$permissions = $templateRightService->getTemplateRightsBatch(
			userId: $this->userId,
			templateIds: $templates->getIdList(),
			rules: ['edit' => ActionDictionary::TEMPLATE_ACTIONS['edit']]
		);

		$parentMap = $templateParentService->getParentIds($templates->getIdList());
		$response = [];

		foreach ($templates as $subTemplate)
		{
			$subTemplateId = (int)$subTemplate->id;
			if (!$permissions[$subTemplateId]['edit'])
			{
				$response[$subTemplateId] = false;
				$this->addError($this->buildForbiddenError());

				continue;
			}

			$canOverride = !$noOverride || (int)$parentMap[$subTemplateId] === 0;
			if (!$canOverride)
			{
				$response[$subTemplateId] = false;
				$this->addError($this->buildForbiddenError('No override parentId'));

				continue;
			}

			$result = (new SetBaseRelationCommand(
				templateId: $subTemplateId,
				userId: $this->userId,
				baseTemplateId: (int)$template->id,
			))->run();

			$response[$subTemplateId] = $result->isSuccess();
			if (!$response[$subTemplateId])
			{
				$this->addErrors($result->getErrors());
			}
		}

		return $response;
	}

	public function deleteAction(
		#[Permission\Update]
		#[Count(min: 1, max: 20)]
		Entity\Template\TemplateCollection $templates,
	): ?array
	{
		$response = [];

		foreach ($templates as $subTemplate)
		{
			$subTemplateId = (int)$subTemplate->id;
			$result = (new DeleteBaseRelationCommand(
				templateId: $subTemplateId,
				userId: $this->userId,
			))->run();

			$response[$subTemplateId] = $result->isSuccess();
			if (!$response[$subTemplateId])
			{
				$this->addErrors($result->getErrors());
			}
		}

		return $response;
	}
}
