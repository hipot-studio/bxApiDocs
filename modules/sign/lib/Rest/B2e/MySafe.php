<?php

namespace Bitrix\Sign\Rest\B2e;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\Response\BFile;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Rest\AccessException;
use Bitrix\Rest\AuthTypeException;
use Bitrix\Rest\Oauth\Auth as OauthAuth;
use Bitrix\Rest\RestException;
use Bitrix\Sign\Access\AccessController;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Controllers\V1\Document\B2eSignedFile;
use Bitrix\Sign\Operation\GetSignedB2eFileUrl;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\EntityFileCode;
use Bitrix\Sign\Type\EntityType;
use CRestServer;
use CRestUtil;
use IRestService;

Loader::includeModule('rest');

final class MySafe extends IRestService
{

	private const LIMIT_DEFAULT = 20;
	private const LIMIT_MAX = 1000;

	public static function onRestServiceBuildDescription(): array
	{
		return [
			'sign.b2e' => [
				'sign.b2e.mysafe.tail' => ['callback' => [self::class, 'getMySafe'], 'options' => []],
				'sign.b2e.personal.tail' => ['callback' => [self::class, 'getPersonal'], 'options' => []],
				CRestUtil::METHOD_DOWNLOAD => ['callback' => [self::class, 'downloadDocument'], 'options' => []],
			],

		];
	}

	/**
	 * @param array $query Input parameters ($_GET, $_POST).
	 * @param int $start (int)$query['start']
	 * @param CRestServer $restServer REST server.
	 *
	 * @return BFile
	 * @throws RestException
	 */
	public static function downloadDocument(array $query, $start, CRestServer $restServer): BFile
	{
		$controller = new B2eSignedFile();
		$controller->configureActions();

		try
		{
			$result = $controller->downloadAction(
				EntityType::MEMBER,
				$id = (int)($query['id'] ?? 0),
				(new Signer())->sign(EntityType::MEMBER . '' . $id, GetSignedB2eFileUrl::B2eFileSalt),
				(int)($query['fileCode'] ?? EntityFileCode::SIGNED),
			);
		}
		catch (\Exception $error)
		{
			throw new RestException($error->getMessage(), $error->getCode());
		}
		if (empty($result))
		{
			[$error] = $controller->getErrors();
			throw new RestException($error?->getMessage(), $error?->getCode());
		}
		else
		{
			return $result;
		}

	}

	/**
	 * @param array $query Input parameters ($_GET, $_POST).
	 * @param int $start (int)$query['start']
	 * @param CRestServer $restServer REST server.
	 *
	 * @return array
	 * @throws AccessException
	 * @throws RestException
	 */
	public static function getMySafe(array $query, $start, CRestServer $restServer): array
	{
		self::checkAuth($restServer);

		if (is_numeric(CurrentUser::get()->getId()) === false)
		{
			throw new AccessException('Access denied for user with malformed id');
		}

		//check access to MySafe action
		$accessController = (new AccessController(CurrentUser::get()->getId()));
		if ($accessController->check(ActionDictionary::ACTION_B2E_MY_SAFE) !== true)
		{
			throw new AccessException('Access denied');
		}

		$memberRepository = Container::instance()->getMemberRepository();
		$documentRepository = Container::instance()->getDocumentRepository();
		$documentService = Container::instance()->getDocumentService();
		$memberService = Container::instance()->getMemberService();
		$listService = Container::instance()->getSafeListService();

		$limit = filter_var($query['limit'] ?? self::LIMIT_DEFAULT, FILTER_VALIDATE_INT, [
			'options' => [
				'min_range' => 1,
				'max_range' => self::LIMIT_MAX,
				'default' => self::LIMIT_DEFAULT,
			],
		]);
		$offset = filter_var($query['offset'] ?? 0, FILTER_VALIDATE_INT, [
			'options' => [
				'min_range' => 0,
				'default' => 0,
			],
		]);

		$user = $accessController->getUser();
		$isFolderGroupingAllowed = \Bitrix\Sign\Config\Feature::instance()->isSafeFolderGroupingAllowed();

		// The MySafe REST surface always returns a flat list of documents (backward compatible). With
		// folder grouping on, the ACL is folder-aware: folderless documents keep the legacy
		// owner-scope while documents inside readable folders are included too, and each row carries
		// the name of the folder it lives in. With the feature off the shared owner-scope remains in
		// effect and the response shape is preserved.
		$documentFilter = $isFolderGroupingAllowed
			? $listService->buildFlatDocumentFilter($user)
			: $listService->buildOwnerScopeFilter($user);

		$memberCollection = $memberRepository->listB2eMembersWithResultFilesForMySafe(
			$documentFilter,
			$limit,
			$offset,
		);

		if ($memberCollection->isEmpty())
		{
			return [];
		}

		$documentCollection = $documentRepository->listByIds(
			array_unique(
				array_map(fn($m) => $m->documentId, $memberCollection->toArray())
			)
		);
		$documents = $documentCollection->getArrayByIds();
		$folderNameByFolderId = $isFolderGroupingAllowed
			? self::resolveFolderNamesByMembers($memberCollection)
			: [];

		$result = [];
		foreach ($memberCollection as $member)
		{
			$document = $documents[$member->documentId];
			$row = [
				'id' => $member->id,
				'title' => $documentService->getTitleWithAutoNumber($document),
				'create_date' => $document->dateCreate?->format(\DateTimeInterface::ATOM),
				'signed_date' => $member->dateSigned?->format(\DateTimeInterface::ATOM),
				'creator_id' => $document->createdById,
				'member_id' => $memberService->getUserIdForMember($member, $document),
				'role' => $member->role,
				'file_url' => CRestUtil::getDownloadUrl(['id' => $member->id], $restServer),
			];

			// Folder grouping adds the record's folder name (null for folderless records), keeping the
			// legacy flat response shape otherwise unchanged.
			if ($isFolderGroupingAllowed)
			{
				$folderId = $member->folderId;
				$row['folderName'] = ($folderId !== null && $folderId > 0)
					? ($folderNameByFolderId[$folderId] ?? null)
					: null;
			}

			$result[] = $row;
		}

		return $result;
	}

	/**
	 * Batch-resolves the folder title for every folder referenced by the page members (no N+1): one
	 * query for the unique FOLDER_IDs of the current page.
	 *
	 * @return array<int, string> folderId => title
	 */
	private static function resolveFolderNamesByMembers(
		\Bitrix\Sign\Item\MemberCollection $members,
	): array
	{
		$folderIds = [];
		foreach ($members as $member)
		{
			$folderId = $member->folderId;
			if ($folderId !== null && $folderId > 0)
			{
				$folderIds[$folderId] = $folderId;
			}
		}

		if (empty($folderIds))
		{
			return [];
		}

		return Container::instance()->getSafeFolderRepository()->getTitlesByIds(array_values($folderIds));
	}

	/**
	 * @param array $query Input parameters ($_GET, $_POST).
	 * @param int $start (int)$query['start']
	 * @param CRestServer $restServer REST server.
	 *
	 * @return array
	 * @throws AccessException
	 */
	public static function getPersonal(array $query, $start, CRestServer $restServer): array
	{
		self::checkAuth($restServer);

		$memberRepository = Container::instance()->getMemberRepository();
		$documentRepository = Container::instance()->getDocumentRepository();
		$documentService = Container::instance()->getDocumentService();

		$limit = filter_var($query['limit'] ?? self::LIMIT_DEFAULT, FILTER_VALIDATE_INT, [
			'options' => [
				'min_range' => 1,
				'max_range' => self::LIMIT_MAX,
				'default' => self::LIMIT_DEFAULT,
			],
		]);
		$offset = filter_var($query['offset'] ?? 0, FILTER_VALIDATE_INT, [
			'options' => [
				'min_range' => 0,
				'default' => 0,
			],
		]);

		$memberCollection = $memberRepository->listSignersByUserIdIsDone(
			CurrentUser::get()->getId(),
			Query::filter(),
			$limit,
			$offset
		);

		if ($memberCollection->isEmpty())
		{
			return [];
		}

		$documentCollection = $documentRepository->listByIds(
			array_unique(
				array_map(fn($m) => $m->documentId, $memberCollection->toArray())
			)
		);
		$documents = $documentCollection->getArrayByIds();

		$result = [];
		foreach ($memberCollection as $member)
		{
			$document = $documents[$member->documentId];
			$result[] = [
				'id' => $member->id,
				'title' => $documentService->getTitleWithAutoNumber($document),
				'signed_date' => $member->dateSigned?->format(\DateTimeInterface::ATOM),
				'file_url' => CRestUtil::getDownloadUrl(['id' => $member->id], $restServer),
			];
		}

		return $result;
	}

	/**
	 * @param CRestServer $restServer
	 *
	 * @return void
	 * @throws AccessException
	 */
	private static function checkAuth(CRestServer $restServer): void
	{
		global $USER;

		if (!$USER->isAuthorized())
		{
			throw new AccessException("User authorization required");
		}

		if ($restServer->getAuthType() !== OauthAuth::AUTH_TYPE)
		{
			throw new AuthTypeException("Application context required");
		}

		if (!Storage::instance()->isB2eAvailable())
		{
			throw new AccessException('Access denied');
		}
	}

}
