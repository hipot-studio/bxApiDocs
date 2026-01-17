<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template;

use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\Control\Exception\TemplateAddException;
use Bitrix\Tasks\Control\Exception\TemplateUpdateException;
use Bitrix\Tasks\Control\Handler\Exception\TemplateFieldValidateException;
use Bitrix\Tasks\Replication\Template\Option\Options;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\EnableReplication;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\ResetCache;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\SendPush;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\UpdateDependencies;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\UpdateMembers;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\UpdateParent;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\UpdateRights;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\UpdateTags;
use Bitrix\Tasks\V2\Internal\Service\Template\Prepare\Update\EntityFieldService;

class UpdateService
{
	public function __construct(
		private readonly ValidationService $validationService,
		private readonly TemplateRepositoryInterface $templateRepository,
	)
	{

	}

	public function update(Entity\Template $template, UpdateConfig $config)
	{
		$this->loadMessages();

		$entityBefore = $this->templateRepository->getById($template->getId());
		if ($entityBefore === null)
		{
			throw new TaskNotExistsException();
		}

		// we do validation here, because we need merge states and get new entity to check
		$this->validate($entityBefore, $template);

		$compatibilityRepository = Container::getInstance()->getTemplateCompatabilityRepository();

		$fullTemplateData = $compatibilityRepository->getTemplateData($template->getId());

		[$template, $fields] = (new EntityFieldService())->prepare($template, $config, $fullTemplateData);

		$isReplicateParamsChanged = $this->isReplicateParametersChanged($fullTemplateData, $fields);

		$id = $this->templateRepository->save($template);

		// $templateAfterUpdate = $compatibilityRepository->getTemplateData($template->getId());

		// $templateObject = $this->save($fields);

		// $this->setMembers($fields);
		(new UpdateMembers())($fields);
		// $this->setTags($fields);
		(new UpdateTags($config))($fields);
		// $this->setDependTasks($fields);
		(new UpdateDependencies())($fields);
		// todo
		// $this->ufManager->Update(\Bitrix\Tasks\Util\UserField\Task\Template::getEntityCode(), $this->templateId, $fields, $this->userId);

		// $this->updateParent($fields);
		(new UpdateParent())($fields);

		if ($isReplicateParamsChanged && !$config->isSkipAgent())
		{
			(new EnableReplication())($fields);
			// $this->enableReplication($fields);
		}

		// $this->resetCache();
		(new ResetCache())($fields);

		// $this->sendUpdatePush($fields);
		(new SendPush($config))($fields, $fullTemplateData);

		(new UpdateRights($config))($fields, $fullTemplateData);

		$this->templateRepository->invalidate($id);

		$template = $this->templateRepository->getById($id);
		if ($template === null)
		{
			throw new TemplateAddException();
		}

		return [$template, $fields];
	}

	private function loadMessages(): void
	{
		Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/tasks/lib/control/template.php');
		Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/tasks/lib/control/handler/templatefieldhandler.php');
		Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/tasks/lib/control/handler/taskfieldhandler.php');
	}

	private function validate(Entity\Template $entityBefore, Entity\Template $entityAfter): void
	{
		$props = array_filter($entityAfter->toArray());

		$validationResult = $this->validationService->validate($entityBefore->cloneWith($props));
		if (!$validationResult->isSuccess())
		{
			$errors = $validationResult->getErrors();

			throw new CommandValidationException($errors);
		}
	}

	private function isReplicateParametersChanged(array $template, array $fields): bool
	{
		if ((int)($template['BASE_TEMPLATE_ID'] ?? null) > 0)
		{
			return false;
		}

		if (isset($fields['REPLICATE']) && ($template['REPLICATE'] ?? null) !== $fields['REPLICATE'])
		{
			return true;
		}

		if (!isset($fields['REPLICATE_PARAMS']))
		{
			return false;
		}

		$before = $template['REPLICATE_PARAMS'] ?? null;
		$before = is_string($before) ? unserialize($before, ['allowed_classes' => false]) : $before;

		$after = $fields['REPLICATE_PARAMS'];
		$after = is_string($after) ? unserialize($after, ['allowed_classes' => false]) : $after;
		if (!is_array($before))
		{
			return is_array($after);
		}

		return !Options::isNewAndCurrentOptionsEquals($before, $after);
	}
}
