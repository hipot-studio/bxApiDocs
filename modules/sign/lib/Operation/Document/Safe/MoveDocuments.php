<?php

namespace Bitrix\Sign\Operation\Document\Safe;

use Bitrix\Main;
use Bitrix\Main\Application;
use Bitrix\Sign\Contract\Operation as OperationContract;
use Bitrix\Sign\Exception\SignException;
use Bitrix\Sign\Repository\Document\SafeFolderRelationRepository;
use Bitrix\Sign\Repository\Document\SafeFolderRepository;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Result\Result;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Sign\Document\Safe\AccessService;
use Bitrix\Sign\Service\Sign\Document\Safe\ListService;
use Bitrix\Sign\Type\Document\Folder\EntityType;

/**
 * Moves company safe records into a target folder (targetFolderId = null means "No folder").
 *
 * Folder membership lives on the member (grid row), not on the document. The incoming ids are safe
 * grid row ids, i.e. member ids. Each selected member is moved on its own by updating its
 * polymorphic relation row, and NO other member of the same document is touched. This fixes the "4
 * rows moved instead of 2" bug where relocating one grid row dragged both copies of a company
 * document.
 *
 * Members may come from different source folders. Partial success: members the user may move are
 * relocated, the rest are reported per input member id in the "errors" list (MEMBER_NOT_FOUND when
 * the member is missing, ACCESS_DENIED when the move is not allowed). The relation PARENT_ID is
 * updated in a transaction.
 */
class MoveDocuments implements OperationContract
{
	private readonly SafeFolderRelationRepository $safeFolderRelationRepository;
	private readonly SafeFolderRepository $safeFolderRepository;
	private readonly AccessService $accessService;
	private readonly MemberRepository $memberRepository;
	private readonly ListService $listService;

	/**
	 * @param list<int> $memberIds safe grid row ids (member ids)
	 */
	public function __construct(
		private readonly array $memberIds,
		private readonly ?int $targetFolderId,
		?SafeFolderRelationRepository $safeFolderRelationRepository = null,
		?SafeFolderRepository $safeFolderRepository = null,
		?AccessService $accessService = null,
		?MemberRepository $memberRepository = null,
		?ListService $listService = null,
	)
	{
		$container = Container::instance();
		$this->safeFolderRelationRepository = $safeFolderRelationRepository ?? $container->getSafeFolderRelationRepository();
		$this->safeFolderRepository = $safeFolderRepository ?? $container->getSafeFolderRepository();
		$this->accessService = $accessService ?? $container->getSafeAccessService();
		$this->memberRepository = $memberRepository ?? $container->getMemberRepository();
		$this->listService = $listService ?? $container->getSafeListService();
	}

	public function launch(): Main\Result
	{
		$memberIds = array_values(array_unique(array_filter(
			array_map('intval', $this->memberIds),
			static fn(int $id): bool => $id > 0,
		)));

		if (empty($memberIds))
		{
			return Result::createByErrorMessage('Document list cannot be empty');
		}

		if ($this->targetFolderId !== null && $this->targetFolderId > 0)
		{
			$targetFolder = $this->safeFolderRepository->getById($this->targetFolderId);
			if ($targetFolder === null)
			{
				return Result::createByErrorMessage('Target folder not found');
			}

			// Reuse the already loaded target folder for the per-member access checks below so it is
			// not reloaded once per member (N+1).
			$this->accessService->primeFolder($targetFolder);
		}

		$membersById = [];
		foreach ($this->memberRepository->listB2eMembersWithResultFilesForMySafe(
			Main\ORM\Query\Query::filter()->whereIn('ID', $memberIds),
			count($memberIds),
			0,
			countTotal: false,
		) as $member)
		{
			$membersById[(int)$member->id] = $member;
		}

		$user = $this->accessService->getCurrentUserAccessModel();
		$ownerScopeMemberIds = [];
		if ($user !== null && !$this->listService->canAccessLevel($user, null))
		{
			return Result::createByErrorMessage('Access denied');
		}

		if ($user !== null)
		{
			$ownerScopeFilter = Main\ORM\Query\Query::filter()
				->whereIn('ID', $memberIds)
				->where($this->listService->buildOwnerScopeFilter($user))
			;
			foreach ($this->memberRepository->listB2eMembersWithResultFilesForMySafe(
				$ownerScopeFilter,
				count($memberIds),
				0,
				countTotal: false,
			) as $member)
			{
				$ownerScopeMemberIds[(int)$member->id] = true;
			}
		}

		$movableMemberIds = [];
		$errors = [];
		foreach ($memberIds as $memberId)
		{
			$member = $membersById[$memberId] ?? null;
			if ($member === null)
			{
				$errors[] = ['documentId' => $memberId, 'code' => 'MEMBER_NOT_FOUND'];

				continue;
			}

			if (!$this->accessService->canMoveMember(
				$member->folderId,
				$this->targetFolderId,
				(int)$member->createdById,
				isset($ownerScopeMemberIds[$memberId]),
			))
			{
				$errors[] = ['documentId' => $memberId, 'code' => 'ACCESS_DENIED'];

				continue;
			}

			$movableMemberIds[] = $memberId;
		}

		if ($movableMemberIds === [] && $errors !== [])
		{
			return (new Main\Result())
				->setData(['moved' => [], 'errors' => $errors])
				->addError(new Main\Error('No documents can be moved', 'NO_DOCUMENTS_MOVED'))
			;
		}

		if (!empty($movableMemberIds))
		{
			$connection = Application::getConnection();
			$connection->startTransaction();
			try
			{
				if (
					$this->targetFolderId !== null
					&& $this->targetFolderId > 0
					&& !$this->safeFolderRelationRepository->lockFolderRelations([$this->targetFolderId])
				)
				{
					throw new SignException('Target folder not found');
				}

				$updateResult = $this->safeFolderRelationRepository->updateParent(
					$this->targetFolderId ?? 0,
					$movableMemberIds,
					EntityType::MEMBER,
				);
				if (!$updateResult->isSuccess())
				{
					throw new SignException('Move documents error');
				}

				$connection->commitTransaction();
			}
			catch (\Throwable $e)
			{
				$connection->rollbackTransaction();

				return Result::createByErrorMessage($e->getMessage());
			}
		}

		return (new Main\Result())->setData([
			'moved' => $movableMemberIds,
			'errors' => $errors,
		]);
	}
}
