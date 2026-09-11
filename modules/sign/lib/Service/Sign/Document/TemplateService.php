<?php

namespace Bitrix\Sign\Service\Sign\Document;

use Bitrix\Main\Result;
use Bitrix\Main;
use Bitrix\Sign\Item\Document\Template;
use Bitrix\Sign\Item\Document\TemplateCollection;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Repository\Document\TemplateFolderRelationRepository;
use Bitrix\Sign\Repository\Document\TemplateFolderRepository;
use Bitrix\Sign\Repository\Document\TemplateRepository;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Sign\Document\Template\AccessService;
use Bitrix\Sign\Type\Document\InitiatedByType;
use Bitrix\Sign\Type\Template\EntityType;
use Bitrix\Sign\Type\Template\Status;
use Bitrix\Sign\Type\Template\Visibility;

final class TemplateService
{
	private const TEMPLATE_BATCH_SIZE = 300;
	private const FOLDER_BATCH_SIZE = 300;
	private const TEMPLATE_PROCESSING_ATTEMPTS = 3;
	private const FOLDER_UPDATE_ATTEMPTS = 3;

	private readonly TemplateRepository $templateRepository;
	private readonly TemplateFolderRepository $templateFolderRepository;
	private readonly TemplateFolderRelationRepository $templateFolderRelationRepository;
	private readonly AccessService $accessService;
	private readonly MemberRepository $memberRepository;
	private readonly DocumentRepository $documentRepository;

	public function __construct(
		?TemplateRepository $templateRepository = null,
		?TemplateFolderRepository $templateFolderRepository = null,
		?TemplateFolderRelationRepository $templateFolderRelationRepository = null,
		?AccessService $accessService = null,
		?MemberRepository $memberRepository = null,
		?DocumentRepository $documentRepository = null,
	)
	{
		$container = Container::instance();
		$this->templateRepository = $templateRepository ?? $container->getDocumentTemplateRepository();
		$this->templateFolderRepository = $templateFolderRepository ?? $container->getTemplateFolderRepository();
		$this->templateFolderRelationRepository = $templateFolderRelationRepository ?? $container->getTemplateFolderRelationRepository();
		$this->accessService = $accessService ?? $container->getTemplateAccessService();
		$this->memberRepository = $memberRepository ?? $container->getMemberRepository();
		$this->documentRepository = $documentRepository ?? $container->getDocumentRepository();
	}

	public function markTemplatesWithUserAsIncomplete(int $userId): Result
	{
		$result = new Result();
		$affectedFolderIds = [];
		$relationProcessingResult = new Result();
		for ($attempt = 0; $attempt < self::TEMPLATE_PROCESSING_ATTEMPTS; $attempt++)
		{
			$relationProcessingResult = $this->processTemplateUserRelations($userId);
			foreach ($relationProcessingResult->getData()['affectedFolderIds'] ?? [] as $folderId)
			{
				$affectedFolderIds[$folderId] = true;
			}

			if ($relationProcessingResult->isSuccess())
			{
				break;
			}
		}
		$result->addErrors($relationProcessingResult->getErrors());
		$result->addErrors(
			$this->updateAffectedFolderVisibilities(array_keys($affectedFolderIds))->getErrors(),
		);

		return $result;
	}

	private function processTemplateUserRelations(int $userId): Result
	{
		$result = new Result();
		$affectedFolderIds = [];
		$relationBatch = [];
		try
		{
			foreach ($this->iterateTemplateUserRelations($userId) as $relation)
			{
				$relationBatch[] = $relation;
				if (count($relationBatch) < self::TEMPLATE_BATCH_SIZE)
				{
					continue;
				}

				$this->appendTemplateUserRelationBatchResult(
					$result,
					$affectedFolderIds,
					$relationBatch,
					$userId,
				);
				$relationBatch = [];
			}

			if (!empty($relationBatch))
			{
				$this->appendTemplateUserRelationBatchResult(
					$result,
					$affectedFolderIds,
					$relationBatch,
					$userId,
				);
			}
		}
		catch (\Throwable $exception)
		{
			$result->addError(new Main\Error($exception->getMessage()));
		}
		$result->setData(['affectedFolderIds' => array_keys($affectedFolderIds)]);

		return $result;
	}

	/**
	 * @return \Generator<array{templateId: int, documentId: int, memberId: int, isRepresentative: bool}>
	 */
	private function iterateTemplateUserRelations(int $userId): \Generator
	{
		yield from $this->memberRepository->iterateDirectTemplateUserRelations($userId);
		yield from $this->iterateRepresentativeTemplateUserRelations($userId);
	}

	/**
	 * @return \Generator<array{templateId: int, documentId: int, memberId: int, isRepresentative: bool}>
	 */
	private function iterateRepresentativeTemplateUserRelations(int $userId): \Generator
	{
		$templateIdsByDocumentId = [];
		$documents = $this->documentRepository->iterateB2eTemplateDocumentIdsByRepresentative($userId);
		foreach ($documents as $document)
		{
			$templateIdsByDocumentId[$document['documentId']] = $document['templateId'];
			if (count($templateIdsByDocumentId) < self::TEMPLATE_BATCH_SIZE)
			{
				continue;
			}

			yield from $this->getRepresentativeTemplateUserRelations($templateIdsByDocumentId);
			$templateIdsByDocumentId = [];
		}

		if (!empty($templateIdsByDocumentId))
		{
			yield from $this->getRepresentativeTemplateUserRelations($templateIdsByDocumentId);
		}
	}

	/**
	 * Documents whose assignee is an HR role keep a role id in REPRESENTATIVE_ID instead of a user id,
	 * so only documents with a company assignee are treated as a representative relation.
	 *
	 * @param array<int, int> $templateIdsByDocumentId
	 * @return list<array{templateId: int, documentId: int, memberId: int, isRepresentative: bool}>
	 */
	private function getRepresentativeTemplateUserRelations(array $templateIdsByDocumentId): array
	{
		$documentIds = $this->memberRepository->filterDocumentIdsWithCompanyAssignee(
			array_keys($templateIdsByDocumentId),
		);

		return array_map(
			static fn(int $documentId): array => [
				'templateId' => $templateIdsByDocumentId[$documentId],
				'documentId' => $documentId,
				'memberId' => 0,
				'isRepresentative' => true,
			],
			$documentIds,
		);
	}

	/**
	 * @param array<int, true> $affectedFolderIds
	 * @param list<array{templateId: int, documentId: int, memberId: int, isRepresentative: bool}> $relations
	 */
	private function appendTemplateUserRelationBatchResult(
		Result $result,
		array &$affectedFolderIds,
		array $relations,
		int $userId,
	): void
	{
		$batchResult = $this->processTemplateUserRelationBatch($relations, $userId);
		$result->addErrors($batchResult->getErrors());
		foreach ($batchResult->getData()['affectedFolderIds'] ?? [] as $folderId)
		{
			$affectedFolderIds[$folderId] = true;
		}
	}

	/**
	 * @param list<array{templateId: int, documentId: int, memberId: int, isRepresentative: bool}> $relations
	 */
	private function processTemplateUserRelationBatch(array $relations, int $userId): Result
	{
		$result = new Result();
		$affectedFolderIds = [];
		try
		{
			$relations = $this->memberRepository->filterExistingTemplateUserRelations($relations, $userId);
			if (empty($relations))
			{
				return $result;
			}

			$processedTemplateIds = [];
			$updatableTemplateIds = [];
			$templateIds = array_values(array_unique(array_column($relations, 'templateId')));
			$templates = $this->templateRepository->getByIds($templateIds);
			foreach ($templates as $template)
			{
				if ($template->status !== Status::NEW || $template->visibility !== Visibility::INVISIBLE)
				{
					$updatableTemplateIds[] = $template->getId();
				}

				$processedTemplateIds[$template->getId()] = true;
				if ($template->folderId > 0)
				{
					$affectedFolderIds[$template->folderId] = true;
				}
			}

			$updateResult = $this->templateRepository->updateStatusesAndVisibilitiesIfUserRelationExists(
				$updatableTemplateIds,
				Status::NEW,
				Visibility::INVISIBLE,
				$userId,
			);
			if (!$updateResult->isSuccess())
			{
				return $result->addErrors($updateResult->getErrors());
			}

			$memberIds = [];
			$representativeDocumentIds = [];
			foreach ($relations as $relation)
			{
				if (!isset($processedTemplateIds[$relation['templateId']]))
				{
					continue;
				}

				if ($relation['isRepresentative'])
				{
					$representativeDocumentIds[$relation['documentId']] = true;
				}
				else
				{
					$memberIds[$relation['memberId']] = true;
				}
			}

			if (!empty($memberIds))
			{
				$result->addErrors(
					$this->memberRepository
						->deleteByIdsForUser(array_keys($memberIds), $userId)
						->getErrors(),
				);
			}
			if (!empty($representativeDocumentIds))
			{
				$result->addErrors(
					$this->documentRepository
						->resetRepresentativeByIds(array_keys($representativeDocumentIds), $userId)
						->getErrors(),
				);
			}
		}
		catch (\Throwable $exception)
		{
			$result->addError(new Main\Error($exception->getMessage()));
		}

		$result->setData(['affectedFolderIds' => array_keys($affectedFolderIds)]);

		return $result;
	}

	/**
	 * @param list<int> $folderIds
	 */
	private function updateAffectedFolderVisibilities(array $folderIds): Result
	{
		$result = new Result();
		$folderIds = array_values(array_unique(array_filter(
			$folderIds,
			static fn(int $folderId): bool => $folderId > 0,
		)));
		foreach (array_chunk($folderIds, self::FOLDER_BATCH_SIZE) as $folderIdBatch)
		{
			$result->addErrors($this->updateAffectedFolderVisibilityBatch($folderIdBatch)->getErrors());
		}

		return $result;
	}

	/**
	 * @param list<int> $folderIds
	 */
	private function updateAffectedFolderVisibilityBatch(array $folderIds): Result
	{
		$batchResult = new Result();
		for ($attempt = 0; $attempt < self::FOLDER_UPDATE_ATTEMPTS; $attempt++)
		{
			$batchResult = $this->updateFolderVisibilitiesByVisibleTemplateExistence($folderIds);
			if ($batchResult->isSuccess())
			{
				break;
			}
		}

		return $batchResult;
	}

	/**
	 * @param list<int> $folderIds
	 */
	private function updateFolderVisibilitiesByVisibleTemplateExistence(array $folderIds): Result
	{
		try
		{
			return $this->templateFolderRepository->updateVisibilitiesByVisibleTemplateExistence($folderIds);
		}
		catch (\Throwable $exception)
		{
			return (new Result())->addError(new Main\Error($exception->getMessage()));
		}
	}

	/**
	 * @param array<array{entityType: string, id: int}> $entities
	 */
	public function moveToFolder(array $entities, int $folderId): Result
	{
		$result = new Result();

		$templateIds = $this->getTemplateIdsFromEntities($entities);
		if (empty($templateIds))
		{
			return $result->addError(new Main\Error('Folder relation not created'));
		}

		$allTemplatesIsInitiatedByCompany = $this->templateRepository->isAllInitiatedByTypeByIds(
			$templateIds,
			InitiatedByType::COMPANY,
		);
		if (!$allTemplatesIsInitiatedByCompany)
		{
			return $result->addError(new Main\Error('All templates should be initiated by company'));
		}

		if ($folderId !== 0)
		{
			$targetFolder = $this->templateFolderRepository->getById($folderId);
			if ($targetFolder === null)
			{
				return $result->addError(new Main\Error('Target folder not found'));
			}

			if (!$this->accessService->hasAccessToEdit($targetFolder))
			{
				return $result->addError(new Main\Error('No access rights to edit target folder'));
			}

			$changeFolderVisibilityOnTemplateAdditionalResult = $this->changeFolderVisibilityOnTemplateAddition($templateIds, $folderId);
			if (!$changeFolderVisibilityOnTemplateAdditionalResult->isSuccess())
			{
				return $result->addError(new Main\Error('Change folder visibility error'));
			}
		}

		if (!$this->templateFolderRelationRepository->areAllEntitiesInSameParent($templateIds, EntityType::TEMPLATE))
		{
			return $result->addError(new Main\Error('All templates must be in the same folder'));
		}

		$parentIdForTemplateInFolderBeforeUpdate = $this->templateFolderRelationRepository->getByEntityIdAndType(
			$templateIds[0],
			EntityType::TEMPLATE,
		)
			?->parentId
		;

		if ($parentIdForTemplateInFolderBeforeUpdate > 0)
		{
			$sourceFolder = $this->templateFolderRepository->getById($parentIdForTemplateInFolderBeforeUpdate);
			if ($sourceFolder !== null && !$this->accessService->hasAccessToEdit($sourceFolder))
			{
				return $result->addError(new Main\Error('No access rights to edit source folder'));
			}
		}

		$updateParentIdForTemplatesResult = $this->updateParentIdForTemplates(
			$templateIds,
			$parentIdForTemplateInFolderBeforeUpdate,
			$folderId)
		;
		if (!$updateParentIdForTemplatesResult->isSuccess())
		{
			return $result->addError(new Main\Error('Update parent id for templates error'));
		}

		if ($folderId === 0)
		{
			$changeFolderVisibilityOnTemplateRemovalResult = $this->changeFolderVisibilityOnTemplateRemoval($parentIdForTemplateInFolderBeforeUpdate);
			if (!$changeFolderVisibilityOnTemplateRemovalResult->isSuccess())
			{
				return $result->addError(new Main\Error('Change folder visibility error'));
			}
		}

		return $result;
	}

	/**
	 * @param list<int> $updatableEntityIds
	 * @return Result
	 */
	public function updateParent(int $parentId, array $updatableEntityIds, EntityType $entityType): Result
	{
		return $this->templateFolderRelationRepository->updateParent($parentId, $updatableEntityIds, $entityType);
	}

	/**
	 * @param array<array{entityType: string, id: int}> $entities
	 * @return list<int>
	 */
	private function getTemplateIdsFromEntities(array $entities): array
	{
		$templateIds = [];
		foreach ($entities as $entity)
		{
			$entityType = EntityType::tryFrom($entity['entityType'] ?? '');

			if ($entityType === null || !$entityType->isTemplate())
			{
				continue;
			}

			$templateIds[] = $entity['id'];
		}

		return $templateIds;
	}

	/**
	 * @param list<int> $templateIds
	 */
	private function changeFolderVisibilityOnTemplateAddition(array $templateIds, int $folderId): Result
	{
		$folderVisible = Visibility::INVISIBLE;
		$templates = $this->templateRepository->getByIds($templateIds);
		foreach ($templates as $template)
		{
			if ($template->visibility->isVisible())
			{
				$folderVisible = Visibility::VISIBLE;
			}
		}

		$result = new Result();
		$folderItem = $this->templateFolderRepository->getById($folderId);
		if ($folderItem === null)
		{
			return $result->addError(new Main\Error('Folder not found'));
		}

		$folderItem->visibility = $folderVisible;

		$updateFolderResult = $this->templateFolderRepository->update($folderItem);
		if (!$updateFolderResult->isSuccess())
		{
			return $result->addError(new Main\Error('Update folder visible error'));
		}

		return $result;
	}

	/**
	 * @param list<int> $templateIds
	 */
	private function updateParentIdForTemplates(array $templateIds, ?int $sourceFolderId, int $destinationFolderId): Result
	{
		$result = new Result();
		if ($sourceFolderId === null)
		{
			return $result->addError(new Main\Error('Parent id for template in folder before update not found'));
		}

		$updateTemplateFolderRelationResult = $this->templateFolderRelationRepository->updateParent(
			$destinationFolderId,
			$templateIds,
			EntityType::TEMPLATE,
		);

		if (!$updateTemplateFolderRelationResult->isSuccess())
		{
			return $result->addError(new Main\Error('Update relations error'));
		}

		return $result;
	}

	private function changeFolderVisibilityOnTemplateRemoval(?int $folderId): Result
	{
		$folderChildRelations =  $this->templateFolderRelationRepository->getAllByParentIdAndType(
			$folderId,
			EntityType::TEMPLATE
		);

		$templateInFolderIds = $folderChildRelations->getEntityIds();
		$templatesInFolder = $this->templateRepository->getByIds($templateInFolderIds);
		$hasVisibleTemplate = false;
		foreach ($templatesInFolder as $template)
		{
			if ($template->visibility === Visibility::VISIBLE)
			{
				$hasVisibleTemplate = true;
				break;
			}
		}

		$result = new Result();
		if (!$hasVisibleTemplate && $folderId !== 0)
		{
			$folderItem = $this->templateFolderRepository->getById($folderId);
			if ($folderItem === null)
			{
				return $result->addError(new Main\Error('Folder not found'));
			}

			$folderItem->visibility = Visibility::INVISIBLE;
			$updateFolderResult = $this->templateFolderRepository->update($folderItem);
			if (!$updateFolderResult->isSuccess())
			{
				return $result->addError(new Main\Error('Update folder visible error'));
			}
		}

		return $result;
	}

	public function changeFolderVisibilityOnTemplateCompletion(int $folderId): Result
	{
		if ($folderId < 1)
		{
			return new Result();
		}

		return $this->templateFolderRepository->updateVisibility($folderId, Visibility::VISIBLE);
	}

	public function changeVisibility(int $templateId, Visibility $visibility): Result
	{
		$result = new Result();
		$currentTemplate = $this->templateRepository->getById($templateId);

		if ($this->isInvalidVisibilityChange($currentTemplate, $visibility))
		{
			return $result->addError(new Main\Error('Incorrect visibility status'));
		}

		$updateTemplateVisibilityResult = $this->templateRepository->updateVisibility($templateId, $visibility);
		if (!$updateTemplateVisibilityResult->isSuccess())
		{
			return $result->addError(new Main\Error('Update visibility template error'));
		}

		if ($currentTemplate->folderId !== null)
		{
			if (!$this->updateFolderVisibilityBasedOnTemplates($currentTemplate)->isSuccess())
			{
				return $result->addError(new Main\Error('Failed to change folder visibility'));
			}
		}

		return $result;
	}

	private function isInvalidVisibilityChange(?Template $template, Visibility $visibility): bool
	{
		$currentStatus = $template?->status ?? Status::NEW;
		return $currentStatus === Status::NEW && $visibility === Visibility::VISIBLE;
	}

	private function updateFolderVisibilityBasedOnTemplates(Template $currentTemplate): Result
	{
		$currentTemplateRelation = $this->templateFolderRelationRepository->getByEntityIdAndType(
			$currentTemplate->getId(),
			EntityType::TEMPLATE
		);

		$templateIdsInFolder = $this->getTemplateIdsInFolder($currentTemplateRelation->parentId);
		$isAnyTemplateVisible = $this->isAnyTemplateVisible($templateIdsInFolder);

		return $this->templateFolderRepository->updateVisibility(
			$currentTemplateRelation->parentId,
			$isAnyTemplateVisible ? Visibility::VISIBLE : Visibility::INVISIBLE
		);
	}

	private function getTemplateIdsInFolder(int $parentId): array
	{
		$templateRelationsInCurrentFolder = $this->templateFolderRelationRepository->getAllByParentIdAndType(
			$parentId,
			EntityType::TEMPLATE
		);

		return $templateRelationsInCurrentFolder->getEntityIds();
	}

	/**
	 * @param list<int> $templateIds
	 */
	private function isAnyTemplateVisible(array $templateIds): bool
	{
		$allTemplatesInFolder = $this->templateRepository->getByIds($templateIds);
		foreach ($allTemplatesInFolder as $template)
		{
			if ($template->visibility === Visibility::VISIBLE)
			{
				return true;
			}
		}

		return false;
	}

	public function updateTitle(int $templateId, string $title): Result
	{
		return $this->templateRepository->updateTitle($templateId, $title);
	}

	public function getById(int $templateId): ?Template
	{
		if ($templateId < 1)
		{
			return null;
		}

		return $this->templateRepository->getById($templateId);
	}

	/**
	 * @param list<int> $templateIds
	 * @return TemplateCollection
	 */
	public function getByIds(array $templateIds): TemplateCollection
	{
		return $this->templateRepository->getByIds($templateIds);
	}

	public function getByUid(string $templateUid): ?Template
	{
		if ($templateUid === '')
		{
			return null;
		}

		return $this->templateRepository->getByUid($templateUid);
	}

	public function getCompletedAndVisibleCompanyTemplateByUid(string $templateUid): ?Template
	{
		if ($templateUid === '')
		{
			return null;
		}

		return $this->templateRepository->getCompletedAndVisibleCompanyTemplateByUid($templateUid);
	}

	public function hasAnyInvisibleTemplates(TemplateCollection $templates): bool
	{
		foreach ($templates as $template)
		{
			if ($template->visibility->isInvisible())
			{
				return true;
			}
		}

		return false;
	}
}
