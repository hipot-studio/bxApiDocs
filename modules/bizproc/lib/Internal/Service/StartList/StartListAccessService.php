<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\StartList;

use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Engine\CurrentUser;
use CBPCanUserOperateOperation;
use CBPDocument;
use CUser;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Which of the templates the start list found an employee may actually start, and on which documents.
 *
 * The list narrows templates by document alone, so a template whose start is forbidden by its own rule
 * or by the document module could reach the grid with a working start button. This service repeats the
 * same StartWorkflow check as the start action and passes the template identifier in parameters.
 *
 * The pair is the unit, not the template: a template offered on several documents keeps the documents
 * the employee may start it on and loses the rest, so the grid never opens it on a forbidden one. A
 * template left without a single allowed pair leaves the list.
 *
 * The check is fail-closed: a pair whose check threw is dropped and logged, never shown. The groups of
 * the employee are read once. Document states are not loaded here: document implementations that need
 * them load them inside their own check, while implementations such as CRM avoid the query altogether.
 *
 * Reading the groups and the check itself are separate methods so that a test double can count them and
 * make each of them fail; nothing else is meant to be overridden.
 */
class StartListAccessService
{
	private const LOGGER_ID = 'bizproc.start_list_order';

	private ?LoggerInterface $logger = null;

	/**
	 * @param int $userId Employee the list is built for.
	 * @param array<int, list<array{document: array, triggerType: string|null}>> $templateMatches Pairs
	 * the list built, grouped by template; a document is the context the component carries, so it holds
	 * documentType and documentId.
	 *
	 * @return array<int, list<array{document: array, triggerType: string|null}>> The same pairs without
	 * the forbidden ones and without the templates left with none.
	 */
	public function filter(int $userId, array $templateMatches): array
	{
		if (!$templateMatches)
		{
			return [];
		}

		$userGroups = $this->getUserGroups($userId);

		$allowedMatches = [];
		foreach ($templateMatches as $templateId => $matches)
		{
			$templateId = (int)$templateId;

			$allowed = [];
			foreach ($matches as $match)
			{
				if ($this->isStartAllowed($userId, $templateId, $match['document'], $userGroups))
				{
					$allowed[] = $match;
				}
			}

			if ($allowed)
			{
				$allowedMatches[$templateId] = $allowed;
			}
		}

		return $allowedMatches;
	}

	private function isStartAllowed(int $userId, int $templateId, array $document, array $userGroups): bool
	{
		try
		{
			return $this->canStartWorkflow($userId, $document['documentId'], [
				'UserGroups' => $userGroups,
				'WorkflowTemplateId' => $templateId,
			]);
		}
		catch (Throwable $error)
		{
			$this->warn('Bizproc: the start of a template was not confirmed and is hidden from the list', [
				'templateId' => $templateId,
				'documentType' => implode('@', $document['documentType']),
				'error' => $error->getMessage(),
				'errorClass' => get_class($error),
			]);

			return false;
		}
	}

	/**
	 * The groups of the current employee come from the request; the groups of anybody else are read out
	 * of the portal, as the start of a document reads them.
	 */
	protected function getUserGroups(int $userId): array
	{
		$currentUser = CurrentUser::get();
		$currentUserId = $currentUser->getId();

		return ($currentUserId !== null && (int)$currentUserId === $userId)
			? $currentUser->getUserGroups()
			: CUser::GetUserGroup($userId);
	}

	protected function canStartWorkflow(int $userId, array $documentId, array $parameters): bool
	{
		return (bool)CBPDocument::canUserOperateDocument(
			CBPCanUserOperateOperation::StartWorkflow,
			$userId,
			$documentId,
			$parameters,
		);
	}

	protected function getLogger(): LoggerInterface
	{
		return $this->logger ??= (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
	}

	/**
	 * @param array<string, mixed> $context The template and the type of the document only - never the
	 * document itself, the parameters of the start or the values of its fields.
	 */
	private function warn(string $message, array $context): void
	{
		try
		{
			$this->getLogger()->warning($message, $context);
		}
		catch (Throwable)
		{
			// a log nobody could write is no reason to take the list down
		}
	}
}
