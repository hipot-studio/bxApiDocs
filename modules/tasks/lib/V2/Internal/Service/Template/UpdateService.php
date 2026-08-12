<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template;

use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\Control\Exception\TemplateAddException;
use Bitrix\Tasks\Replication\Template\Option\Options;
use Bitrix\Tasks\Util\Type;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateReplicateParamsRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\EnableReplication;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\ResetCache;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\SendPush;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\UpdateDependencies;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\UpdateMembers;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\UpdateParams;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\UpdateParent;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\UpdateRights;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare\PrepareReplication;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\UpdateTags;
use Bitrix\Tasks\V2\Internal\Service\Template\Prepare\Update\EntityFieldService;
use Bitrix\Tasks\V2\Internal\Service\Template\Recent\UpdateTemplateRecentMessage;

class UpdateService
{
	public function __construct(
		private readonly ValidationService $validationService,
		private readonly TemplateRepositoryInterface $templateRepository,
		private readonly TemplateReplicateParamsRepositoryInterface $templateReplicateParamsRepository,
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
		[$taskIdBeforeUpdate, $taskIdAfterUpdate] = $this->getTaskIdsBeforeAndAfterUpdate($fullTemplateData, $fields);

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

		if ($isReplicateParamsChanged)
		{
			if (!$config->isSkipAgent())
			{
				(new EnableReplication())($fields, $this->isReplicationEnabled($fields, $fullTemplateData));
				// $this->enableReplication($fields);
			}

			$this->templateReplicateParamsRepository->invalidateByTemplateId($id);
		}

		if ($taskIdBeforeUpdate !== $taskIdAfterUpdate)
		{
			$this->invalidateByTaskIds($taskIdBeforeUpdate, $taskIdAfterUpdate);
		}

		(new UpdateParams())($fields);

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

		if ($this->shouldUpdateRecent($entityBefore, $template, $fields, $fullTemplateData))
		{
			(new UpdateTemplateRecentMessage(
				userId: $config->getUserId(),
				templateId: $id,
				action: UpdateTemplateRecentMessage::ACTION_ADD,
			))->sendByInternalQueueId();
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

		if (
			isset($fields['REPLICATE'])
			&& ((($template['REPLICATE'] ?? null) === 'Y') !== $fields['REPLICATE'])
		)
		{
			return true;
		}

		if (
			isset($fields['REPLICATE_TYPE'])
			&& ($template['REPLICATE_TYPE'] ?? null) !== $fields['REPLICATE_TYPE']
		)
		{
			return true;
		}

		if (!isset($fields['REPLICATE_PARAMS']))
		{
			return false;
		}

		$before = $this->normalizeReplicateParams($template['REPLICATE_PARAMS'] ?? null);
		$after = $this->normalizeReplicateParams($fields['REPLICATE_PARAMS']);
		if ($before === null)
		{
			return $after !== null;
		}

		if ($after === null)
		{
			return true;
		}

		if (
			!empty($fields[PrepareReplication::DEADLINE_OFFSET_HEALED])
			&& $this->isOnlyDeadlineOffsetChanged($before, $after)
		)
		{
			return false;
		}

		return !Options::isNewAndCurrentOptionsEquals($before, $after);
	}

	private function isReplicationEnabled(array $fields, array $fullTemplateData): bool
	{
		if (array_key_exists('REPLICATE', $fields))
		{
			return $fields['REPLICATE'] === true;
		}

		return ($fullTemplateData['REPLICATE'] ?? 'N') === 'Y';
	}

	private function shouldUpdateRecent(
		Entity\Template $templateBefore,
		Entity\Template $templateAfter,
		array $fields,
		array $fullTemplateData,
	): bool
	{
		if ($this->isReplicateCountOnlyUpdate($templateBefore, $templateAfter, $fields, $fullTemplateData))
		{
			return false;
		}

		if ($this->isUnchangedNextExecutionTimeOnlyUpdate($templateAfter, $fields, $fullTemplateData))
		{
			return false;
		}

		if ($this->isDeadlineOffsetHealOnly($fullTemplateData, $fields))
		{
			return false;
		}

		return true;
	}

	/**
	 * Agent tick that only bumps replication counter (optionally refreshing service params keys).
	 */
	private function isReplicateCountOnlyUpdate(
		Entity\Template $templateBefore,
		Entity\Template $templateAfter,
		array $fields,
		array $fullTemplateData,
	): bool
	{
		$isReplicateCountIncreased =
			$templateBefore->replicateCount !== null
			&& $templateAfter->replicateCount !== null
			&& $templateAfter->replicateCount > $templateBefore->replicateCount
		;

		if (!$isReplicateCountIncreased)
		{
			return false;
		}

		if (!$this->hasOnlyServiceTopLevelFieldsForRecentSuppression($fields))
		{
			return false;
		}

		return $this->areIncomingReplicateParamsUnchangedExceptServiceKeys($fields, $fullTemplateData);
	}

	/**
	 * Agent/service tick that only refreshes NEXT_EXECUTION_TIME to the same value — skip recent.
	 * Do not suppress when other top-level fields or schedule params changed.
	 */
	private function isUnchangedNextExecutionTimeOnlyUpdate(
		Entity\Template $templateAfter,
		array $fields,
		array $fullTemplateData,
	): bool
	{
		$nextExecutionTime = $templateAfter->replicateParams?->nextExecutionTime;
		if ($nextExecutionTime === null || !isset($fields['REPLICATE_PARAMS']['NEXT_EXECUTION_TIME']))
		{
			return false;
		}

		if ($fields['REPLICATE_PARAMS']['NEXT_EXECUTION_TIME'] !== $nextExecutionTime)
		{
			return false;
		}

		if (!$this->hasOnlyServiceTopLevelFieldsForRecentSuppression($fields))
		{
			return false;
		}

		return $this->areIncomingReplicateParamsUnchangedExceptServiceKeys($fields, $fullTemplateData);
	}

	/**
	 * Heal restored DEADLINE_OFFSET only — no other meaningful field/params changes.
	 */
	private function isDeadlineOffsetHealOnly(array $template, array $fields): bool
	{
		if (empty($fields[PrepareReplication::DEADLINE_OFFSET_HEALED]))
		{
			return false;
		}

		if (isset($fields['REPLICATE']) || isset($fields['REPLICATE_TYPE']))
		{
			return false;
		}

		if (!$this->hasOnlyServiceTopLevelFieldsForRecentSuppression($fields))
		{
			return false;
		}

		if (!isset($fields['REPLICATE_PARAMS']))
		{
			return true;
		}

		$before = $this->normalizeReplicateParams($template['REPLICATE_PARAMS'] ?? null);
		$after = $this->normalizeReplicateParams($fields['REPLICATE_PARAMS'] ?? null);

		if ($before === null || $after === null)
		{
			return false;
		}

		return $this->isOnlyDeadlineOffsetChanged($before, $after);
	}

	/**
	 * True when REPLICATE_PARAMS is absent or differs from DB only by service keys
	 * (NEXT_EXECUTION_TIME; DEADLINE_OFFSET when heal marker is set).
	 */
	private function areIncomingReplicateParamsUnchangedExceptServiceKeys(
		array $fields,
		array $fullTemplateData,
	): bool
	{
		if (!isset($fields['REPLICATE_PARAMS']))
		{
			return true;
		}

		$after = $this->normalizeReplicateParams($fields['REPLICATE_PARAMS']);
		if ($after === null)
		{
			return false;
		}

		$before = $this->normalizeReplicateParams($fullTemplateData['REPLICATE_PARAMS'] ?? null) ?? [];

		return $this->areReplicateParamsEqualIgnoringKeys(
			$before,
			$after,
			$this->getServiceIgnoredReplicateParamKeys($fields),
		);
	}

	private function getServiceIgnoredReplicateParamKeys(array $fields): array
	{
		$ignoredKeys = ['NEXT_EXECUTION_TIME', 'DEADLINE_AFTER'];
		if (!empty($fields[PrepareReplication::DEADLINE_OFFSET_HEALED]))
		{
			$ignoredKeys[] = 'DEADLINE_OFFSET';
		}

		return $ignoredKeys;
	}

	private function normalizeReplicateParams(mixed $raw): ?array
	{
		if (is_array($raw))
		{
			return $raw;
		}

		if (is_string($raw) && $raw !== '')
		{
			$parsed = Type::unSerializeArray($raw);

			return is_array($parsed) ? $parsed : null;
		}

		return null;
	}

	private function hasOnlyServiceTopLevelFieldsForRecentSuppression(array $fields): bool
	{
		foreach (array_keys($fields) as $fieldName)
		{
			if ($this->isIgnoredFieldForServiceOnlyRecentSuppression($fieldName))
			{
				continue;
			}

			return false;
		}

		return true;
	}

	private function isIgnoredFieldForServiceOnlyRecentSuppression(string $fieldName): bool
	{
		return $fieldName === PrepareReplication::DEADLINE_OFFSET_HEALED
			|| $fieldName === 'REPLICATE_PARAMS'
			|| $fieldName === 'ID'
			|| $fieldName === 'TPARAM_REPLICATION_COUNT'
		;
	}

	private function isOnlyDeadlineOffsetChanged(array $before, array $after): bool
	{
		return $this->areReplicateParamsEqualIgnoringKeys(
			$before,
			$after,
			['DEADLINE_OFFSET', 'DEADLINE_AFTER'],
		);
	}

	private function areReplicateParamsEqualIgnoringKeys(
		array $before,
		array $after,
		array $ignoredKeys,
	): bool
	{
		foreach ($ignoredKeys as $key)
		{
			unset($before[$key], $after[$key]);
		}

		$before = Options::validate($before);
		$after = Options::validate($after);

		foreach ($ignoredKeys as $key)
		{
			unset($before[$key], $after[$key]);
		}

		return $this->areOptionsEqualSymmetrically($before, $after);
	}

	/**
	 * Symmetric equality: keys present only on either side are compared (unlike Options::isNewAndCurrentOptionsEquals).
	 */
	private function areOptionsEqualSymmetrically(array $left, array $right): bool
	{
		$keys = array_unique(array_merge(array_keys($left), array_keys($right)));
		foreach ($keys as $key)
		{
			if (($left[$key] ?? null) != ($right[$key] ?? null))
			{
				return false;
			}
		}

		return true;
	}

	private function getTaskIdsBeforeAndAfterUpdate(array $template, array $fields): array
	{
		$taskIdBeforeUpdate = (int)($template['TASK_ID'] ?? 0);
		$taskIdAfterUpdate = isset($fields['TASK_ID']) ? (int)$fields['TASK_ID'] : $taskIdBeforeUpdate;

		return [$taskIdBeforeUpdate, $taskIdAfterUpdate];
	}

	private function invalidateByTaskIds(int $taskIdBeforeUpdate, int $taskIdAfterUpdate): void
	{
		if ($taskIdBeforeUpdate > 0)
		{
			$this->templateReplicateParamsRepository->invalidateByTaskId($taskIdBeforeUpdate);
		}

		if (
			$taskIdAfterUpdate > 0
			&& $taskIdAfterUpdate !== $taskIdBeforeUpdate
		)
		{
			$this->templateReplicateParamsRepository->invalidateByTaskId($taskIdAfterUpdate);
		}
	}
}
