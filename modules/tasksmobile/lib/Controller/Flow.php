<?php

namespace Bitrix\TasksMobile\Controller;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Mobile\Provider\UserRepository;
use Bitrix\Tasks\Integration\CRM;
use Bitrix\Tasks\Flow\Access\FlowAccessController;
use Bitrix\Tasks\Flow\Access\FlowAction;
use Bitrix\Tasks\Provider\TemplateProvider;
use Bitrix\TasksMobile\Dto\FlowRequestFilter;
use Bitrix\TasksMobile\Dto\TaskTemplateDto;
use Bitrix\TasksMobile\Dto\TaskTemplateTagDto;
use Bitrix\TasksMobile\Enum\TaskPriority;
use Bitrix\TasksMobile\Provider\FlowProvider;
use Bitrix\TasksMobile\Provider\GroupProvider;
use Bitrix\TasksMobile\Settings;
use Bitrix\TasksMobile\Service\Template\TaskTemplateDataBuilder;

final class Flow extends Base
{
	protected function getQueryActionNames(): array
	{
		return [
			'loadItems',
			'loadByIds',
			'getTaskCreateMetadata',
		];
	}

	/**
	 * @restMethod tasksmobile.Flow.loadItems
	 * @param FlowRequestFilter $flowSearchParams
	 * @param PageNavigation $pageNavigation
	 * @param array $extra
	 * @param string $order
	 * @return array
	 */
	public function loadItemsAction(
		FlowRequestFilter $flowSearchParams,
		PageNavigation $pageNavigation,
		array $extra = [],
		string $order = FlowProvider::ORDER_ACTIVITY,
	): array
	{
		if (!Settings::getInstance()->isTaskFlowAvailable())
		{
			$this->addError(new Error('Flow feature is not available.'));

			return [];
		}

		$result = (new FlowProvider(
			$this->getCurrentUser()->getId(),
			$order,
			$extra,
			$flowSearchParams,
			$pageNavigation
		))->getFlows();

		return $this->convertKeysToCamelCase($result);
	}

	/**
	 * @restMethod tasksmobile.Flow.disableShowFlowsFeatureInfoFlagInDB
	 * @return bool
	 */
	public function disableShowFlowsFeatureInfoFlagInDBAction(): bool
	{
		if (!Settings::getInstance()->isTaskFlowAvailable())
		{
			$this->addError(new Error('Flow feature is not available.'));

			return false;
		}

		return (new FlowProvider($this->getCurrentUser()->getId()))->disableShowFlowsFeatureInfoFlag();
	}

	/**
	 * @restMethod tasksmobile.Flow.subscribeUserToPull
	 * @return bool
	 */
	public function subscribeUserToPullAction(): bool
	{
		if (!Settings::getInstance()->isTaskFlowAvailable())
		{
			$this->addError(new Error('Flow feature is not available.'));

			return false;
		}

		return (new FlowProvider($this->getCurrentUser()->getId()))->subscribeCurrentUserToPull();
	}

	/**
	 * @restMethod tasksmobile.Flow.loadByIds
	 * @return array
	 */
	public function loadByIdsAction(array $ids = []): array
	{
		if (empty($ids))
		{
			return [];
		}

		if (!Settings::getInstance()->isTaskFlowAvailable())
		{
			$this->addError(new Error('Flow feature is not available.'));

			return [];
		}

		$result = (new FlowProvider($this->getCurrentUser()->getId()))->getFlowsById($ids);

		return $this->convertKeysToCamelCase($result);
	}

	/**
	 * @return array
	 */
	public function getCountersAction(): array
	{
		if (!Settings::getInstance()->isTaskFlowAvailable())
		{
			$this->addError(new Error('Flow feature is not available.'));

			return [];
		}

		return (new FlowProvider($this->getCurrentUser()->getId()))->getTotalCounters();
	}

	/**
	 * @return array
	 */
	public function getSearchBarPresetsAction(): array
	{
		if (!Settings::getInstance()->isTaskFlowAvailable())
		{
			$this->addError(new Error('Flow feature is not available.'));

			return [];
		}
		$presets = (new FlowProvider($this->getCurrentUser()->getId()))->getSearchBarPresets();

		return [
			'presets' => $presets,
			'counters' => [],
		];
	}

	/**
	 * @restMethod tasksmobile.Flow.getTaskCreateMetadata
	 */
	public function getTaskCreateMetadataAction(int $flowId, CurrentUser $user, ?int $copyId = null): array
	{
		if (!Settings::getInstance()->isTaskFlowAvailable())
		{
			$this->addError(new Error('Flow feature is not available.'));

			return [];
		}

		if (!FlowAccessController::can($user->getId(), FlowAction::READ, $flowId))
		{
			$this->addError(new Error('Flow is not found or not accessible'));

			return [];
		}

		$flow = (new FlowProvider($user->getId()))->getFlowById($flowId);

		if (!$flow)
		{
			$this->addError(new Error('Flow is not found or not accessible'));

			return [];
		}

		$template = null;
		$userIds = [];

		$taskTemplateDataBuilder = new TaskTemplateDataBuilder($this->getCurrentUser()?->getId(), $flow->templateId);

		if ($flow->templateId)
		{
			$select = [
				'TITLE',
				'DESCRIPTION',
				'PRIORITY',
				'ACCOMPLICES',
				'AUDITORS',
				'TAGS',
				CRM\UserField::getMainSysUFCode(),
			];
			$data = TemplateProvider::getById($flow->templateId, $select);

			if ($data)
			{
				$accomplices = unserialize($data['ACCOMPLICES'], ['allowed_classes' => false]);
				$auditors = unserialize($data['AUDITORS'], ['allowed_classes' => false]);

				$template = new TaskTemplateDto(
					id: $flow->templateId,
					name: $data['TITLE'],
					description: $data['DESCRIPTION'],
					priority: TaskPriority::tryFrom((int)$data['PRIORITY']) ?? TaskPriority::Normal,
					accomplices: array_map('intval', $accomplices ?? []),
					auditors: array_map('intval', $auditors ?? []),
					files: $taskTemplateDataBuilder->prepareDiskFiles(),
					checklist: $this->convertKeysToCamelCase($taskTemplateDataBuilder->prepareChecklist()),
					tags: array_map(
						fn($tag) => new TaskTemplateTagDto(id: $tag, name: $tag),
						$data['TAGS'] ?? [],
					),
					crm: $taskTemplateDataBuilder->prepareCrmElements($data),
				);

				$userIds = array_unique(
					array_merge($template->accomplices, $template->auditors)
				);
			}
		}

		return $this->convertKeysToCamelCase([
			'template' => $template,
			'checklist' => $taskTemplateDataBuilder->prepareChecklistCopy($copyId),
			'flow' => $flow,
			'groups' => GroupProvider::loadByIds([$flow->groupId]),
			'users' => empty($userIds) ? [] : UserRepository::getByIds($userIds),
		]);
	}
}
