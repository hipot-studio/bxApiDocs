<?php

declare(strict_types=1);

namespace Bitrix\CrmMobile\Controller\Action;

use Bitrix\Crm\Service\Container;
use Bitrix\CrmMobile\Controller\Action;
use Bitrix\CrmMobile\Kanban\View\ItemViewType;
use Bitrix\CrmMobile\Kanban\View\ViewTypeStorage;
use Bitrix\CrmMobile\Public\Feature\CompactListItem;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Mobile\Trait\PublicErrorsTrait;

class SetViewTypeAction extends Action
{
	use PublicErrorsTrait;

	public function run(int $entityTypeId, string $type)
	{
		$this->checkModules();
		if ($this->hasErrors())
		{
			return $this->showErrors();
		}

		$result = $this->setViewType($entityTypeId, $type);
		if (!$result->isSuccess())
		{
			$errors = $this->markErrorsAsPublic($result->getErrors());
			$this->addErrors($errors);
		}
	}

	private function setViewType(int $entityTypeId, string $type): Result
	{
		$result = new Result();

		if (!Feature::isEnabled(CompactListItem::class))
		{
			return $result->addError(
				new Error('Compact item view is not available on this portal'),
			);
		}

		$viewType = ItemViewType::tryFrom($type);
		if ($viewType === null)
		{
			return $result->addError(
				new Error('Item view type is not supported'),
			);
		}

		if (!Container::getInstance()->getFactory($entityTypeId))
		{
			return $result->addError(
				new Error('Entity type is not supported'),
			);
		}

		if (!ViewTypeStorage::set($entityTypeId, $viewType))
		{
			return $result->addError(
				new Error('Item view type was not saved'),
			);
		}

		return $result;
	}
}
