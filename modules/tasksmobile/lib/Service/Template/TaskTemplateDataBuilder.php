<?php

namespace Bitrix\TasksMobile\Service\Template;

use Bitrix\Crm\Service\Display;
use Bitrix\Crm\Service\Display\Field;
use Bitrix\Main\Loader;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\TaskAccessController;
use Bitrix\Tasks\CheckList\Task\TaskCheckListFacade;
use Bitrix\Tasks\CheckList\Template\TemplateCheckListFacade;
use Bitrix\TasksMobile\Dto\DiskFileDto;
use Bitrix\TasksMobile\Dto\RelatedCrmItemDto;
use Bitrix\TasksMobile\UserField\Dto\UserFieldDto;
use Bitrix\TasksMobile\UserField\Provider\TaskUserFieldProvider;
use Bitrix\Tasks\Integration\CRM;
use Bitrix\TasksMobile\Provider as TasksMobileProvider;

final class TaskTemplateDataBuilder
{
	private $userId;
	private $templateId;

	public function __construct(int $userId, int $templateId)
	{
		$this->userId = $userId;
		$this->templateId = $templateId;
	}

	public function prepareChecklistCopy(?int $taskId): ?array
	{
		if ($taskId === null)
		{
			return null;
		}

		$canReadTask = TaskAccessController::can(
			$this->userId,
			ActionDictionary::ACTION_TASK_READ,
			$taskId
		);

		if (!$canReadTask)
		{
			return null;
		}

		$items = (new TasksMobileProvider\ChecklistProvider($this->userId, TaskCheckListFacade::class))
			->getChecklistTree($taskId, true);

		return empty($items) ? null : $items;
	}

	public function prepareChecklist(): ?array
	{
		$items = (new TasksMobileProvider\ChecklistProvider($this->userId, TemplateCheckListFacade::class))
			->getChecklistTree($this->templateId, true);

		return empty($items) ? null : $items;
	}

	public function prepareDiskFiles(): array
	{
		$diskFileProvider = new TasksMobileProvider\DiskFileProvider($this->userId);
		$attachments = $diskFileProvider->getDiskFileAttachmentsByTemplate($this->templateId);

		return array_values(array_map(fn($attachment) => DiskFileDto::make($attachment), $attachments));
	}

	/**
	 * @param array $data
	 * @return RelatedCrmItemDto[]
	 */
	public function prepareCrmElements(array $data): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		$ufCrmTaskCode = CRM\UserField::getMainSysUFCode();
		if (empty($data[$ufCrmTaskCode]) || !is_array($data[$ufCrmTaskCode]))
		{
			return [];
		}

		$ufCrmTask = CRM\UserField::getSysUFScheme()[$ufCrmTaskCode];
		$displayField =
			Field::createByType('crm', $ufCrmTaskCode)
				->setIsMultiple($ufCrmTask['MULTIPLE'] === 'Y')
				->setIsUserField(true)
				->setUserFieldParams($ufCrmTask)
				->setContext(Field::MOBILE_CONTEXT)
		;
		$display = new Display(0, [$ufCrmTaskCode => $displayField]);

		$items = CRM\Fields\Collection::createFromArray($data[$ufCrmTaskCode])->filter();
		$display = $display->setItems([[$ufCrmTaskCode => $items->toArray()]]);
		$res = $display->getValues(0);

		if (
			is_array($res[$ufCrmTaskCode]['config']['entityList'])
			&& count($res[$ufCrmTaskCode]['config']['entityList']) === $items->count()
		)
		{
			$elements = array_values(
				array_combine($items->toArray(), $res[$ufCrmTaskCode]['config']['entityList'])
			);

			return array_map(
				fn($fields) => RelatedCrmItemDto::make($fields),
				$elements
			);
		}

		return [];
	}

	/**
	 * @param string[]|null $crmItemIds
	 * @return RelatedCrmItemDto[]
	 */
	public function prepareCrmElementsByIds(?array $crmItemIds): array
	{
		$ufCrmTaskCode = CRM\UserField::getMainSysUFCode();
		if (!$crmItemIds)
		{
			return [];
		}

		return $this->prepareCrmElements([$ufCrmTaskCode => $crmItemIds]);
	}

	public function prepareUserFields(array $data): array
	{
		return array_map(
			static fn(array $field): UserFieldDto => UserFieldDto::make($field),
			(new TaskUserFieldProvider())->getUserFields($data),
		);
	}
}
