<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Template;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Internals\Log\LogFacade;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Access\Registry\TemplateAccessCacheLoader;
use Bitrix\Tasks\V2\Internal\Access\Service\TemplateRightService;
use Bitrix\Tasks\V2\Internal\Access\Template\Permission;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Replication\Recurrence\RecurrenceRuleReadResult;
use Bitrix\Tasks\V2\Internal\Service\Replication\Recurrence\RecurrenceRuleService;
use Bitrix\Tasks\V2\Internal\Service\Replication\Recurrence\RecurrenceTemplateResolver;
use Bitrix\Tasks\V2\Public\Command\Template\Replication\SetReplicationStateCommand;
use Throwable;

class Replication extends BaseController
{
	public const ERROR_INVALID_INPUT = 'TASKS_REGULAR_RULES_INVALID_INPUT';
	public const ERROR_LIMIT_EXCEEDED = 'TASKS_REGULAR_RULES_LIMIT_EXCEEDED';
	public const ERROR_RULE_READ_FAILED = RecurrenceRuleReadResult::ERROR_RULE_READ_FAILED;

	private const MAX_IDENTIFIERS = 200;

	/**
	 * @ajaxAction tasks.V2.Template.Replication.setState
	 */
	public function setStateAction(
		#[Permission\Update]
		Entity\Template $template,
	): bool
	{
		$result = (new SetReplicationStateCommand(
			template: $template,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return false;
		}

		return true;
	}

	/**
	 * Recurrence rules of a batch of templates or recurring tasks, in the order of the input array.
	 * A single unreadable item does not break the batch: it answers with its own error.
	 *
	 * @ajaxAction tasks.V2.Template.Replication.list
	 *
	 * @param mixed[]|null $templateIds
	 * @param mixed[]|null $taskIds
	 */
	#[CloseSession]
	public function listAction(
		RecurrenceRuleService $ruleService,
		RecurrenceTemplateResolver $templateResolver,
		TemplateAccessCacheLoader $accessCacheLoader,
		TemplateRightService $templateRightService,
		?array $templateIds = null,
		?array $taskIds = null,
	): ?array
	{
		$identifiers = $this->validateIdentifiers($templateIds, $taskIds);
		if ($identifiers === null)
		{
			return null;
		}

		try
		{
			$usesTaskIds = $taskIds !== null;
			$targets = $templateResolver->resolveForReading(
				$usesTaskIds ? null : $identifiers,
				$usesTaskIds ? $identifiers : null,
				$this->userId,
			);
			$resolvedTemplateIds = $templateResolver->getTemplateIds($targets);

			// The task path has already checked template access while resolving; the template path checks
			// it per item and needs the access models warmed once for the whole batch.
			if (!$usesTaskIds)
			{
				$accessCacheLoader->preloadForAccess($this->userId, $resolvedTemplateIds);
			}

			$rulesResult = $ruleService->getRulesResult($resolvedTemplateIds, $this->userId);
		}
		catch (Throwable $exception)
		{
			LogFacade::logThrowable($exception);
			$this->addError(
				new Error(
					(string)Loc::getMessage('TASKS_V2_TEMPLATE_REPLICATION_LIST_READ_FAILED'),
					self::ERROR_RULE_READ_FAILED,
				),
			);

			return null;
		}

		$items = [];
		foreach ($targets as $target)
		{
			$items[] = $this->buildItem($target, $rulesResult, $usesTaskIds, $templateRightService);
		}

		return ['items' => $items];
	}

	/**
	 * @param mixed[]|null $templateIds
	 * @param mixed[]|null $taskIds
	 * @return int[]|null unique positive identifiers of the selector that was actually passed
	 */
	private function validateIdentifiers(?array $templateIds, ?array $taskIds): ?array
	{
		if (($templateIds === null) === ($taskIds === null))
		{
			$this->addInvalidInputError();

			return null;
		}

		$rawIdentifiers = $templateIds ?? $taskIds;
		if ($rawIdentifiers === [])
		{
			$this->addInvalidInputError();

			return null;
		}

		if (count($rawIdentifiers) > self::MAX_IDENTIFIERS)
		{
			$this->addError(
				new Error(
					(string)Loc::getMessage(
						'TASKS_V2_TEMPLATE_REPLICATION_LIST_LIMIT_EXCEEDED',
						['#LIMIT#' => self::MAX_IDENTIFIERS],
					),
					self::ERROR_LIMIT_EXCEEDED,
				),
			);

			return null;
		}

		// A wrong input is never normalized silently: the caller must learn that its batch is not
		// the batch that would have been answered.
		$identifiers = [];
		foreach ($rawIdentifiers as $rawIdentifier)
		{
			if (is_int($rawIdentifier))
			{
				$identifier = $rawIdentifier;
			}
			elseif (
				is_string($rawIdentifier)
				&& preg_match('/^[1-9][0-9]*$/D', $rawIdentifier) === 1
			)
			{
				$identifier = filter_var(
					$rawIdentifier,
					FILTER_VALIDATE_INT,
					['options' => ['min_range' => 1]],
				);
			}
			else
			{
				$identifier = false;
			}

			if (!is_int($identifier) || $identifier <= 0 || isset($identifiers[$identifier]))
			{
				$this->addInvalidInputError();

				return null;
			}

			$identifiers[$identifier] = $identifier;
		}

		return array_values($identifiers);
	}

	/**
	 * @param array{taskId: ?int, templateId: ?int} $target
	 * @return array<string, mixed>
	 */
	private function buildItem(
		array $target,
		RecurrenceRuleReadResult $rulesResult,
		bool $usesTaskIds,
		TemplateRightService $templateRightService,
	): array
	{
		$identifier = $usesTaskIds ? ['taskId' => $target['taskId']] : [];

		$templateId = $target['templateId'];
		if ($templateId === null)
		{
			return $this->buildHiddenItem($identifier);
		}

		$mappingFailed = $rulesResult->hasFailedTemplate($templateId);
		$template = $rulesResult->getRules()[$templateId] ?? null;
		if (!$mappingFailed && $template === null)
		{
			return $this->buildHiddenItem($identifier);
		}

		if (!$usesTaskIds)
		{
			try
			{
				if (!$templateRightService->canView($this->userId, $templateId))
				{
					return $this->buildHiddenItem($identifier);
				}
			}
			catch (Throwable $exception)
			{
				LogFacade::logThrowable($exception);

				return $this->buildFailedItem($identifier, $templateId, false);
			}
		}

		if ($mappingFailed)
		{
			return $this->buildFailedItem($identifier, $templateId, $usesTaskIds);
		}
		if ($template === null)
		{
			return $this->buildHiddenItem($identifier);
		}

		try
		{
			$rule = $this->buildRule($template);
		}
		catch (Throwable $exception)
		{
			LogFacade::logThrowable($exception);

			return $this->buildFailedItem($identifier, $templateId, $usesTaskIds);
		}

		return [
			...$identifier,
			'templateId' => $templateId,
			'rule' => $rule,
			'error' => null,
		];
	}

	/**
	 * @param array{taskId?: ?int} $identifier
	 * @return array<string, mixed>
	 */
	private function buildHiddenItem(array $identifier): array
	{
		return [...$identifier, 'templateId' => null, 'rule' => null, 'error' => null];
	}

	/**
	 * @param array{taskId?: ?int} $identifier
	 * @return array<string, mixed>
	 */
	private function buildFailedItem(array $identifier, int $templateId, bool $usesTaskIds): array
	{
		return [
			...$identifier,
			'templateId' => $usesTaskIds ? null : $templateId,
			'rule' => null,
			'error' => [
				'code' => self::ERROR_RULE_READ_FAILED,
				'message' => (string)Loc::getMessage('TASKS_V2_TEMPLATE_REPLICATION_LIST_READ_FAILED'),
			],
		];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function buildRule(Entity\Template $template): ?array
	{
		if ($template->replicateParams === null)
		{
			return null;
		}

		return [
			'taskId' => $template->taskId,
			'replicate' => $template->replicate,
			'regularityType' => $template->regularityType?->value,
			'replicateParams' => $template->replicateParams->toArray(),
			'replicateCount' => $template->replicateCount,
			'deadlineAfter' => $template->deadlineAfter,
			'matchesWorkTime' => $template->matchesWorkTime,
		];
	}

	private function addInvalidInputError(): void
	{
		$this->addError(
			new Error(
				(string)Loc::getMessage(
					'TASKS_V2_TEMPLATE_REPLICATION_LIST_INVALID_INPUT',
					['#LIMIT#' => self::MAX_IDENTIFIERS],
				),
				self::ERROR_INVALID_INPUT,
			),
		);
	}
}
