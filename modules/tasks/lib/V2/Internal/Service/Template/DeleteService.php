<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Control\Exception\TemplateStopDeleteException;
use Bitrix\Tasks\Control\Exception\WrongTemplateIdException;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete\Config\DeleteConfig;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete\FullDeleteRelations;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete\MoveToRecyclebin;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete\ResetCache;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete\RunBeforeDeleteEvent;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete\RunDeleteEvent;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete\SafeDelete;

class DeleteService
{
	public function __construct(
		private readonly TemplateRepositoryInterface $templateRepository,
	)
	{
	}
	public function delete(int $templateId, DeleteConfig $config): bool
	{
		$this->loadMessages();

		if ($templateId <= 0)
		{
			throw new WrongTemplateIdException();
		}

		$compatabilityRepository = Container::getInstance()->getTemplateCompatabilityRepository();

		$fullTemplateData = $compatabilityRepository->getTemplateData($templateId);

		$isCanceled = !(new RunBeforeDeleteEvent())($fullTemplateData);
		if ($isCanceled)
		{
			throw new TemplateStopDeleteException();
		}

		if ($config->isUnsafeDelete())
		{
			(new FullDeleteRelations($config))($fullTemplateData);

			$this->templateRepository->delete($templateId);

			return $this->finalizeDelete($fullTemplateData);
		}

		(new MoveToRecyclebin($config))($fullTemplateData);

		(new SafeDelete())($fullTemplateData);

		if (!$config->getRuntime()->isMovedToRecyclebin())
		{
			(new FullDeleteRelations($config))($fullTemplateData);

			$this->templateRepository->delete($templateId);
		}

		return $this->finalizeDelete($fullTemplateData);
	}

	private function finalizeDelete($fullTemplateData): bool
	{
		(new RunDeleteEvent())($fullTemplateData);

		(new ResetCache())();

		$this->templateRepository->invalidate($fullTemplateData['ID']);

		return true;
	}

	private function loadMessages(): void
	{
		Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/tasks/lib/control/template.php');
		Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/tasks/lib/control/handler/templatefieldhandler.php');
		Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/tasks/lib/control/handler/taskfieldhandler.php');
	}
}
