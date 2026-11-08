<?php

namespace Bitrix\Sign\Controllers\V1\B2e\Document;

use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Access\AccessController;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Attribute;
use Bitrix\Sign\Config\Feature;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Engine\Controller;
use Bitrix\Sign\Exception\SignException;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\Document\Group as DocumentGroup;
use Bitrix\Sign\Item\DocumentCollection;
use Bitrix\Sign\Operation\Document\Validation\ValidateInviteMessage;
use Bitrix\Sign\Operation\Placeholder\ValidateDocumentPlaceholders;
use Bitrix\Sign\Operation\Result\AddGroupDocumentsResult;
use Bitrix\Sign\Result\Service\Sign\Document\CreateGroupResult;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Sign\Document\GroupService;
use Bitrix\Sign\Type\Access\AccessibleItemType;
use Bitrix\Sign\Operation;
use Bitrix\Sign\Type\Document\InitiatedByType;
use Bitrix\Sign\Type\DocumentStatus;
use Bitrix\Sign\Type\ProviderCode;

final class Group extends Controller
{
	private ?AccessController $accessController = null;

	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_ADD),
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT),
	)]
	public function createAction(): array
	{
		if (!Storage::instance()->isB2eAvailable())
		{
			$this->addError(new Error('B2E is not available'));

			return [];
		}

		$currentUserId = (int)CurrentUser::get()->getId();
		$createResult = Container::instance()->getDocumentGroupService()->create($currentUserId);
		if (!$createResult instanceof CreateGroupResult)
		{
			$this->addError(new Error('Can\'t create document group'));
			$this->addErrors($createResult->getErrors());

			return [];
		}

		return [
			'groupId' => $createResult->group->id,
		];
	}

	#[Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_ADD)]
	public function addDocumentsAction(
		array $files,
		int $groupId = 0,
		bool $hasPlaceholders = false,
		?string $initiatedByType = null,
	): array
	{
		$fileIds = [];
		foreach ($files as $fileId)
		{
			if (!is_string($fileId) || $fileId === '')
			{
				$this->addError(new Error('Invalid file id', 'INVALID_FILE_LIST'));

				return [];
			}

			$fileIds[] = $fileId;
		}

		if ($fileIds === [] || count($fileIds) > GroupService::MAX_DOCUMENT_COUNT)
		{
			$this->addError(new Error('Invalid file count', 'INVALID_FILE_LIST'));

			return [];
		}

		$initiatedBy = $initiatedByType === null
			? InitiatedByType::COMPANY
			: InitiatedByType::tryFrom($initiatedByType)
		;
		if ($initiatedBy === null)
		{
			$this->addError(new Error('Invalid initiated by type', 'INVALID_INITIATED_BY_TYPE'));

			return [];
		}

		$result = (new Operation\Document\AddGroupDocumentsFromFiles(
			fileIds: $fileIds,
			createdById: (int)CurrentUser::get()->getId(),
			groupId: $groupId,
			hasPlaceholders: $hasPlaceholders,
			initiatedByType: $initiatedBy,
		))->launch();

		if (!$result instanceof AddGroupDocumentsResult || !$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return [
			'groupId' => $result->getGroupId(),
			'items' => $result->getItems(),
		];
	}

	#[Attribute\ActionAccess(
		permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
		itemType: AccessibleItemType::DOCUMENT,
		itemIdOrUidRequestKey: 'documentUid',
	)]
	public function attachAction(string $documentUid, int $groupId): array
	{
		if (!Storage::instance()->isB2eAvailable())
		{
			$this->addError(new Error('B2E is not available'));

			return [];
		}

		// The guard runs before the service: its application errors would otherwise tell whether
		// somebody else's group exists and how full it is.
		if (!$this->checkGroupAttachAccessForCurrentUser($groupId, $documentUid))
		{
			$this->addAccessDeniedError();

			return [];
		}

		$attachResult = Container::instance()->getDocumentGroupService()->attach($documentUid, $groupId);
		if (!$attachResult->isSuccess())
		{
			$this->addErrors($attachResult->getErrors());

			return [];
		}

		return [];
	}

	#[Attribute\ActionAccess(
		permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
		itemType: AccessibleItemType::DOCUMENT,
		itemIdOrUidRequestKey: 'documentUid',
	)]
	public function detachAction(string $documentUid): array
	{
		if (!Storage::instance()->isB2eAvailable())
		{
			$this->addError(new Error('B2E is not available'));

			return [];
		}

		$detachResult = Container::instance()->getDocumentGroupService()->detach($documentUid);
		if (!$detachResult->isSuccess())
		{
			$this->addErrors($detachResult->getErrors());

			return [];
		}

		return [];
	}

	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_ADD),
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT),
	)]
	public function documentListAction(int $groupId): array
	{
		if (!Storage::instance()->isB2eAvailable()) {
			$this->addError(new Error('B2E is not available'));

			return [];
		}

		if ($groupId < 1) {
			$this->addError(new Error('Invalid group id'));

			return [];
		}

		$documentList = $this->resolveAccessibleGroupDocuments(
			$groupId,
			$this->checkDocumentReadAccessForCurrentUser(...),
		);
		if ($documentList === null)
		{
			$this->addAccessDeniedError();

			return [];
		}

		$draftDocumentList = Container::instance()
			->getDocumentGroupService()
			->filterDraft($documentList)
		;

		return array_map(
			fn(Document $document) => (new \Bitrix\Sign\Ui\ViewModel\Wizard\Document($document))->toArray(),
			$draftDocumentList->toArray()
		);
	}

	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_ADD),
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT),
	)]
	public function getPlaceholderValidationStatusAction(int $groupId): array
	{
		if ($groupId < 1)
		{
			$this->addError(new Error('Invalid group id'));

			return [];
		}

		$documentList = $this->resolveAccessibleGroupDocumentAccessContexts($groupId);
		if ($documentList === null)
		{
			$this->addAccessDeniedError();

			return [];
		}

		$container = Container::instance();
		$agentService = $container->getDocumentAgentService();
		if ($agentService->hasUploadGroupBlanksChain($groupId))
		{
			return ['ready' => false];
		}

		if (!Storage::instance()->isPlaceholderRestrictionEnabled())
		{
			return ['ready' => true];
		}

		if ($agentService->hasRawPlaceholderWarmupChain($groupId))
		{
			return ['ready' => false];
		}

		$cacheService = $container->getDocumentPlaceholderCacheService();
		$draftDocumentList = $container->getDocumentGroupService()->filterDraft($documentList);
		$warmupScheduled = false;
		foreach ($draftDocumentList as $document)
		{
			if (
				$document->status === DocumentStatus::NEW
				|| $document->id === null
				|| $document->uid === null
				|| $document->blankId === null
				|| $cacheService->getRawPlaceholders($document->id, $document->blankId) !== null
			)
			{
				continue;
			}

			$agentService->scheduleRawPlaceholderWarmup($groupId, $document);
			$warmupScheduled = true;
		}

		return [
			'ready' => !$warmupScheduled,
		];
	}

	/**
	 * @return array{hasUnsupported: bool, reasons: array<string, list<string>>}
	 */
	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_ADD),
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT),
	)]
	public function validatePlaceholdersAction(int $groupId): array
	{
		if ($groupId < 1)
		{
			$this->addError(new Error('Invalid group id'));

			return [];
		}

		$documentList = $this->resolveAccessibleGroupDocumentsWithBatchReadAccess($groupId);
		if ($documentList === null)
		{
			$this->addAccessDeniedError();

			return [];
		}
		if (!Storage::instance()->isPlaceholderRestrictionEnabled())
		{
			return [
				'hasUnsupported' => false,
				'reasons' => [],
			];
		}

		$unsupportedCodesByReason = [];
		$draftDocumentList = Container::instance()->getDocumentGroupService()->filterDraft($documentList);
		foreach ($draftDocumentList as $document)
		{
			try
			{
				$operation = new ValidateDocumentPlaceholders($document);
				$result = $operation->launch();
			}
			catch (\Throwable $exception)
			{
				$this->logPlaceholderValidationFailure($document, [$exception->getMessage()]);

				continue;
			}
			if (!$result->isSuccess())
			{
				$this->logPlaceholderValidationFailure($document, $result->getErrorMessages());

				continue;
			}

			$unsupportedCodesByReason = $this->mergeUnsupportedCodesByReason(
				$unsupportedCodesByReason,
				$operation->getUnsupportedCodesByReason(),
			);
		}

		return [
			'hasUnsupported' => $unsupportedCodesByReason !== [],
			'reasons' => $unsupportedCodesByReason,
		];
	}

	/**
	 * @param array<string, list<string>> $target
	 * @param array<string, list<string>> $source
	 *
	 * @return array<string, list<string>>
	 */
	private function mergeUnsupportedCodesByReason(array $target, array $source): array
	{
		foreach ($source as $reason => $codes)
		{
			foreach ($codes as $code)
			{
				$code = (string)$code;
				$target[$reason] ??= [];
				if (!in_array($code, $target[$reason], true))
				{
					$target[$reason][] = $code;
				}
			}
		}

		return $target;
	}

	/** @param list<string> $errors */
	private function logPlaceholderValidationFailure(Document $document, array $errors): void
	{
		Container::instance()->getLogger('Controller')->warning(
			'Unable to validate placeholders of B2E document {documentId}: {errors}',
			[
				'documentId' => $document->id,
				'errors' => implode('; ', $errors),
			],
		);
	}

	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_ADD),
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT),
	)]
	public function configureAction(int $groupId): array
	{
		$this->processDocumentList(
			$groupId,
			function (string $uid): void
			{
				$result = (new Operation\ConfigureFillAndStart($uid))->launch();
				$this->addErrorsFromResult($result);
				if ($result instanceof Operation\Result\ConfigureResult && !$result->completed)
				{
					Container::instance()
						->getDocumentAgentService()
						->addConfigureAndStartAgent($uid)
					;
				}
			},
		);

		return [];
	}

	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_ADD),
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT),
	)]
	public function getFillAndStartProgressAction(int $groupId): array
	{
		$processedCount = 0;
		$completedCount = 0;
		$result = $this->processDocumentList(
			$groupId,
			function (string $uid) use (&$processedCount, &$completedCount): void
			{
				$processedCount++;
				$result = (new Operation\GetFillAndStartProgress($uid))->launch();
				$this->addErrorsFromResult($result);
				if ($result instanceof Operation\Result\ConfigureProgressResult && $result->completed)
				{
					$completedCount++;
				}
			},
		);

		if (!$result)
		{
			return [];
		}

		if ($processedCount === 0)
		{
			$this->addError(new Error(
				Loc::getMessage('SIGN_CONTROLLERS_V1_B2E_DOCUMENT_GROUP_NOTHING_TO_PROCESS'),
				'NOTHING_TO_PROCESS',
			));

			return [];
		}

		return [
			'completed' => $processedCount === $completedCount,
			'progress' => round(100 * $completedCount / $processedCount),
		];
	}

	/**
	 * Stores one invite message on every document of a send batch. The batch is addressed by the
	 * document ids the wizard already holds, so access cannot be delegated to the attribute layer:
	 * every document is resolved and checked individually, and a single denial rejects the whole
	 * request instead of applying the message to part of the batch.
	 *
	 * @param int[] $documentIds
	 */
	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_ADD),
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT),
	)]
	public function modifyInviteMessageAction(array $documentIds, ?string $message = null): array
	{
		if (!Feature::instance()->isDocumentMessageEnabled())
		{
			$this->addError(new Error(
				Loc::getMessage('SIGN_CONTROLLERS_V1_B2E_DOCUMENT_GROUP_MESSAGE_FEATURE_DISABLED'),
				'FEATURE_DISABLED',
			));

			return [];
		}

		$documentIds = array_values(array_unique(array_filter(
			array_map(static fn(mixed $documentId): int => (int)$documentId, $documentIds),
			static fn(int $documentId): bool => $documentId > 0,
		)));

		if ($documentIds === [] || count($documentIds) > GroupService::MAX_DOCUMENT_COUNT)
		{
			$this->addError(new Error(
				Loc::getMessage('SIGN_CONTROLLERS_V1_B2E_DOCUMENT_GROUP_WRONG_INPUT'),
				'WRONG_INPUT',
			));

			return [];
		}

		$validationResult = (new ValidateInviteMessage($message))->launch();
		if (!$validationResult->isSuccess())
		{
			$this->addErrorsFromResult($validationResult);

			return [];
		}

		$documents = Container::instance()->getDocumentRepository()->listByIds($documentIds);
		if ($documents->count() !== count($documentIds))
		{
			$this->addError(new Error(
				Loc::getMessage('SIGN_CONTROLLERS_V1_B2E_DOCUMENT_GROUP_DOCUMENT_NOT_FOUND'),
				'DOCUMENT_NOT_FOUND',
			));

			return [];
		}

		foreach ($documents as $document)
		{
			if (!$this->checkDocumentWriteAccessForCurrentUser($document))
			{
				$this->addError(new Error(
					Loc::getMessage('SIGN_CONTROLLERS_V1_B2E_DOCUMENT_GROUP_ACCESS_DENIED'),
					'ACCESS_DENIED',
				));

				return [];
			}
		}

		$documentService = Container::instance()->getDocumentService();

		// The domain guards run over the whole batch before the transaction is opened, so a rejected
		// document gives a clear error and costs no transaction: the wizard reports the message as not
		// sent and it must not be stored on the documents standing in front of that one.
		$guardResult = $documentService->checkInviteMessageCanBeStoredForDocuments($documents);
		if (!$guardResult->isSuccess())
		{
			$this->addErrorsFromResult($guardResult);

			return [];
		}

		// The guards above cannot cover a status changed after them: the write carries the status
		// condition itself and refuses the batch as a whole, and the transaction takes back the part of
		// the batch its statement did manage to change. The transaction stays short — one statement for
		// the whole batch, which is capped by MAX_DOCUMENT_COUNT.
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$result = $documentService->modifyInviteMessageForDocuments($documents, $message);
			if (!$result->isSuccess())
			{
				$this->addErrorsFromResult($result);

				throw new SignException('Invite message of the batch was not stored');
			}

			$connection->commitTransaction();
		}
		catch (SignException)
		{
			$connection->rollbackTransaction();

			return [];
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();

			throw $exception;
		}

		return ['success' => true];
	}

	private function processDocumentList(int $groupId, callable $callback): bool
	{
		if (!Storage::instance()->isB2eAvailable())
		{
			$this->addError(new Error('B2E is not available'));

			return false;
		}

		if ($groupId < 1)
		{
			$this->addError(new Error('Invalid group id'));

			return false;
		}

		$documentList = $this->resolveAccessibleGroupDocuments(
			$groupId,
			$this->checkDocumentWriteAccessForCurrentUser(...),
		);
		if ($documentList === null)
		{
			$this->addAccessDeniedError();

			return false;
		}

		foreach ($documentList as $document)
		{
			$uid = $document->uid;
			if ($uid === null)
			{
				$this->addError(new Error('Invalid document'));

				continue;
			}

			$callback($uid);
		}

		return true;
	}

	/**
	 * A group opens only as a whole: every document of it must be accessible. Emptiness is checked in
	 * front of completeness, which holds trivially on an empty collection and would open any empty group.
	 *
	 * The composition is read with one document of headroom over the limit: a read capped at the limit
	 * would show an over-filled group truncated, and completeness would hold on the part it was shown.
	 *
	 * @return DocumentCollection|null null denies access, an empty collection is an accessible empty group
	 */
	private function resolveAccessibleGroupDocuments(
		int $groupId,
		\Closure $isDocumentAccessible,
	): ?DocumentCollection
	{
		return $this->resolveAccessibleGroupDocumentCollection(
			$groupId,
			fn(DocumentCollection $documents): ?int => $this->findDeniedDocumentId(
				$documents,
				$isDocumentAccessible,
			),
			static fn(int $id): DocumentCollection => Container::instance()
				->getDocumentGroupService()
				->getDocumentList($id, GroupService::MAX_DOCUMENT_COUNT + 1),
		);
	}

	private function resolveAccessibleGroupDocumentAccessContexts(
		int $groupId,
	): ?DocumentCollection
	{
		return $this->resolveAccessibleGroupDocumentCollection(
			$groupId,
			$this->findDeniedReadAccessDocumentId(...),
			static fn(int $id): DocumentCollection => Container::instance()
				->getDocumentGroupRepository()
				->listDocumentAccessContexts($id, GroupService::MAX_DOCUMENT_COUNT + 1),
		);
	}

	private function resolveAccessibleGroupDocumentsWithBatchReadAccess(
		int $groupId,
	): ?DocumentCollection
	{
		return $this->resolveAccessibleGroupDocumentCollection(
			$groupId,
			$this->findDeniedReadAccessDocumentId(...),
			static fn(int $id): DocumentCollection => Container::instance()
				->getDocumentGroupService()
				->getDocumentList($id, GroupService::MAX_DOCUMENT_COUNT + 1),
		);
	}

	private function resolveAccessibleGroupDocumentCollection(
		int $groupId,
		\Closure $findDeniedDocumentId,
		\Closure $loadDocuments,
	): ?DocumentCollection
	{
		$group = Container::instance()->getDocumentGroupRepository()->getById($groupId);
		if ($group === null)
		{
			$this->logGroupNotFoundOrForeign($groupId);

			return null;
		}

		$documents = $loadDocuments($groupId);
		if ($documents->count() > GroupService::MAX_DOCUMENT_COUNT)
		{
			$this->logGroupOverLimit($group, $documents->count());

			return null;
		}

		if ($documents->isEmpty())
		{
			if ($group->createdById === (int)CurrentUser::get()->getId())
			{
				return $documents;
			}

			$this->logGroupNotFoundOrForeign($groupId);

			return null;
		}

		$deniedDocumentId = $findDeniedDocumentId($documents);
		if ($deniedDocumentId !== null)
		{
			$this->logGroupDocumentNotAccessible($group, $deniedDocumentId);

			return null;
		}

		return $documents;
	}

	private function findDeniedDocumentId(
		DocumentCollection $documents,
		\Closure $isDocumentAccessible,
	): ?int
	{
		foreach ($documents as $document)
		{
			if (!$isDocumentAccessible($document))
			{
				return $document->id;
			}
		}

		return null;
	}

	private function findDeniedReadAccessDocumentId(DocumentCollection $documents): ?int
	{
		$accessController = $this->getCachedAccessController();
		$editableIds = $accessController->filterAccessibleIds(
			[ActionDictionary::ACTION_B2E_DOCUMENT_EDIT],
			$documents,
		);
		$currentUserId = (int)CurrentUser::get()->getId();
		$ownerDocuments = $documents->filter(
			static fn(Document $document): bool => !isset($editableIds[$document->id])
				&& $document->getOwnerId() === $currentUserId,
		);
		$addableIds = $accessController->filterAccessibleIds(
			[ActionDictionary::ACTION_B2E_DOCUMENT_ADD],
			$ownerDocuments,
		);

		foreach ($documents as $document)
		{
			if (!isset($editableIds[$document->id]) && !isset($addableIds[$document->id]))
			{
				return $document->id;
			}
		}

		return null;
	}

	private function logGroupNotFoundOrForeign(int $groupId): void
	{
		Container::instance()->getLogger('Controller')->debug(
			'B2E document group {groupId} is not accessible to user {userId}: no such group, or an empty group of somebody else',
			[
				'groupId' => $groupId,
				'userId' => (int)CurrentUser::get()->getId(),
			],
		);
	}

	private function logGroupDocumentNotAccessible(DocumentGroup $group, ?int $documentId): void
	{
		$this->logGroupDenial(
			$group,
			'B2E document group {groupId} is not accessible to user {userId}: document {documentId} of it is not accessible',
			[
				'documentId' => $documentId,
			],
		);
	}

	private function logGroupOverLimit(DocumentGroup $group, int $documentCount): void
	{
		$this->logGroupDenial(
			$group,
			'B2E document group {groupId} is not accessible to user {userId}: it holds {documentCount} documents over the limit of {limit}',
			[
				'documentCount' => $documentCount,
				'limit' => GroupService::MAX_DOCUMENT_COUNT,
			],
		);
	}

	/**
	 * The owner of a group sees unusable data of their own and gets an error record; for anybody else
	 * the denial is routine, and probing group ids must leave no error record per existing group.
	 */
	private function logGroupDenial(DocumentGroup $group, string $message, array $context): void
	{
		$userId = (int)CurrentUser::get()->getId();
		$logger = Container::instance()->getLogger('Controller');
		$context += [
			'groupId' => $group->id,
			'userId' => $userId,
		];

		if ($group->createdById === $userId)
		{
			$logger->error($message, $context);

			return;
		}

		$logger->debug($message, $context);
	}

	private function checkGroupAttachAccessForCurrentUser(int $groupId, string $documentUid): bool
	{
		$group = Container::instance()->getDocumentGroupRepository()->getById($groupId);
		if ($group === null)
		{
			return false;
		}

		$currentUserId = (int)CurrentUser::get()->getId();
		if ($group->createdById !== $currentUserId)
		{
			return false;
		}

		$document = Container::instance()->getDocumentRepository()->getByUid($documentUid);

		return $document !== null && $document->createdById === $currentUserId;
	}

	private function checkDocumentReadAccessForCurrentUser(Document $document): bool
	{
		if ($this->checkDocumentWriteAccessForCurrentUser($document))
		{
			return true;
		}

		/* The route admits the add right beside the write one, and on a plain document that right
		   carries no owner scope of its own: the rule answers it by CRM entity type alone. */
		return $document->getOwnerId() === (int)CurrentUser::get()->getId()
			&& $this->checkDocumentAccessForCurrentUser($document, ActionDictionary::ACTION_B2E_DOCUMENT_ADD)
		;
	}

	private function checkDocumentWriteAccessForCurrentUser(Document $document): bool
	{
		return $this->checkDocumentAccessForCurrentUser($document, ActionDictionary::ACTION_B2E_DOCUMENT_EDIT);
	}

	private function checkDocumentAccessForCurrentUser(Document $document, string $action): bool
	{
		$item = Container::instance()->getAccessibleItemFactory()->createFromItem($document);

		return $this->getCachedAccessController()->check($action, $item);
	}

	private function getCachedAccessController(): AccessController
	{
		if ($this->accessController === null)
		{
			$this->accessController = new AccessController((int)CurrentUser::get()->getId());
		}

		return $this->accessController;
	}
}
