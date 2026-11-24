<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Document\DocumentEditorUser;
use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionContext;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkOpenPolicy;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkPasswordService;
use Bitrix\Disk\Internal\Service\UnifiedLink\ShortLinkOpening;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Internals\ExternalLinkTable;
use Bitrix\Disk\Internals\AttachedObjectTable;
use Bitrix\Disk\User;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Bitrix\Main\Web\Json;

/**
 * @phpstan-type VerifiedAccess array{
 *   actorUserId: ?int, sourceType: string, sourceId: int, contextHash: string,
 *   context: array{schemaVersion: int, objectId: int, sourceType: string, sourceId: int,
 *     attachedObjectId: ?int, versionId: ?int, visitorFingerprint: string, confirmationFingerprints: array},
 *   expiresAt: ?DateTime
 * }
 */
final class SharedEditingAccess
{
	/** @return Result<VerifiedAccess> */
	public function verify(
		DocumentSessionContext $context,
		?ShortLinkOpening $shortOpening = null,
		?string $allowedMode = null,
		?UnifiedLinkAccessService $unifiedLinkAccessService = null,
		?string $requiredAuthorization = null,
	): Result
	{
		return $this->verifyContext($context, $shortOpening, $allowedMode, $unifiedLinkAccessService, $requiredAuthorization);
	}

	private function verifyContext(
		DocumentSessionContext $context,
		?ShortLinkOpening $shortOpening,
		?string $allowedMode,
		?UnifiedLinkAccessService $unifiedLinkAccessService,
		?string $requiredAuthorization,
		?File $lockedFile = null,
	): Result
	{
		$attachedId = $context->getAttachedObjectId();
		$linkId = $context->getExternalLinkId()
			?? ($shortOpening !== null ? (int)$shortOpening->getExternalLink()->getId() : null)
		;
		$seedObjectIds = array_filter([
			(int)$context->getObjectId(),
			(int)($lockedFile?->getId() ?? 0),
			(int)($lockedFile?->getRealObjectId() ?? 0),
			(int)($shortOpening?->getFile()->getId() ?? 0),
			(int)($shortOpening?->getFile()->getRealObjectId() ?? 0),
		]);

		return DocumentStateRepository::withPreLockedObjects(
			$seedObjectIds,
			$linkId !== null ? [$linkId] : [],
			$shortOpening !== null ? [$shortOpening->getCode()] : [],
			$attachedId !== null ? [$attachedId] : [],
			$shortOpening !== null || $linkId === null || $unifiedLinkAccessService !== null,
			function () use (
				$context,
				$shortOpening,
				$allowedMode,
				$unifiedLinkAccessService,
				$requiredAuthorization,
				$lockedFile,
				$attachedId,
				$linkId,
			): Result {
				// Revalidation uses the locked file; an initial verification reads by ID.
				$fresh = DocumentStateRepository::shouldBypassRepeatableRead();
				$file = $fresh
					? $this->loadFile($context->getObjectId()) : ($lockedFile ?? File::loadById($context->getObjectId()));
				if (!$file || $file->isDeleted() || (int)$file->getRealObjectId() !== $context->getObjectId())
				{
					return $this->refuse();
				}
				$attached = $attachedId !== null ? $this->loadAttachedObject($attachedId) : null;
				if ($attachedId !== null && (!$attached || $attached->isSpecificVersion()
					|| (int)$this->loadFile((int)$attached->getObjectId())?->getRealObjectId() !== (int)$file->getRealObjectId()))
				{
					return $this->refuse();
				}

				$userId = $this->getCurrentUserId();
				$actor = $this->getActorUserId($userId);
				$link = null;
				$source = $shortOpening !== null ? 'unified' : ($linkId !== null ? 'external'
					: ($attachedId !== null ? 'attached' : 'internal'))
				;
				$sourceId = $linkId ?? $attachedId ?? 0;
				$confirmations = [];
				$session = $this->makeSession($file, $context, $userId);
				if ($linkId !== null)
				{
					if ($shortOpening !== null)
					{
						if ((int)$shortOpening->getExternalLink()->getId() !== $linkId)
						{
							return $this->refuse();
						}
						$revalidated = $shortOpening->revalidateForEditor(
							$session, $allowedMode, $userId === 0 || DocumentEditorUser::isCurrentUserDocumentEditor(), $userId,
						);
						if ($revalidated === null)
						{
							return $this->refuse();
						}
						$link = $revalidated->getExternalLink();
						if ((int)$link->getId() !== $linkId || !DocumentStateRepository::externalLinkMatchesFile($link, $file))
						{
							return $this->refuse();
						}
					}
					elseif ($fresh)
					{
						$discoveredRows = DocumentStateRepository::discoverRowsForFreshRead(
							ExternalLinkTable::query()->setSelect(['*'])->where('ID', $linkId),
						);
						$discoveredLink = !empty($discoveredRows[0]) ? ExternalLink::buildFromArray($discoveredRows[0]) : null;
						if (!$discoveredLink || !DocumentStateRepository::externalLinkMatchesFile($discoveredLink, $file))
						{
							return $this->refuse();
						}
						$link = $this->loadExternalLink($linkId);
						if (!$link || (int)$link->getObjectId() !== (int)$discoveredLink->getObjectId())
						{
							return $this->refuse();
						}
					}
					else
					{
						$link = $this->loadExternalLink($linkId);
						if (!$link || !DocumentStateRepository::externalLinkMatchesFile($link, $file))
						{
							return $this->refuse();
						}
					}
					if (!$link || $link->isExpired()
						|| $link->isSpecificVersion()
						|| ($link->getDeathTime() !== null && $link->getDeathTime()->getTimestamp() <= time())
						|| !ServiceLocator::getInstance()->get(ExternalLinkOpenPolicy::class)->canOpenByPortalPolicy($link))
					{
						return $this->refuse();
					}
					if ($shortOpening !== null)
					{
						if (!$revalidated->allowsEditBySource())
						{
							if ($actor === null)
							{
								return $this->refuse();
							}
							$service = $unifiedLinkAccessService ?? ServiceLocator::getInstance()->get(UnifiedLinkAccessService::class);
							if ($requiredAuthorization === 'internal-unified')
							{
								if (!$service->checkFreshUnifiedLinkSource($file, $userId)->canEdit())
								{
									return $this->refuse();
								}
								$confirmations['unifiedLink'] = $this->unifiedLinkFingerprint($file);
							}
							elseif ($requiredAuthorization === 'native')
							{
								if (!$this->hasNativeEditAccess($file, $context, $userId))
								{
									return $this->refuse();
								}
							}
							elseif (!$this->hasNativeEditAccess($file, $context, $userId))
							{
								if (!$service->checkFreshUnifiedLinkSource($file, $userId)->canEdit())
								{
									return $this->refuse();
								}
								$confirmations['unifiedLink'] = $this->unifiedLinkFingerprint($file);
							}
						}
						elseif ($requiredAuthorization !== null)
						{
							return $this->refuse();
						}
					}
					elseif ($requiredAuthorization !== null)
					{
						return $this->refuse();
					}
					elseif ($unifiedLinkAccessService !== null && $actor !== null
						&& !DocumentEditorUser::isCurrentUserDocumentEditor()
						&& User::loadById($userId)?->getExternalAuthId() !== 'call')
					{
						if (!$link->allowEdit() || !$unifiedLinkAccessService->check(
							$file, $attached, userId: $userId, externalLink: $link,
						)->canEdit())
						{
							return $this->refuse();
						}
						$confirmations['classicLink'] = $this->classicLinkFingerprint($file, $linkId);
					}
					elseif (!$link->allowEdit() || ($link->hasPassword()
						&& !ServiceLocator::getInstance()->get(ExternalLinkPasswordService::class)->isConfirmed($link)))
					{
						return $this->refuse();
					}
				}
				elseif ($userId === 0)
				{
					return $this->refuse();
				}
				elseif ($requiredAuthorization !== 'internal-unified' && $this->hasNativeEditAccess($file, $context, $userId))
				{
					// Native rights remain independent of a general link.
				}
				elseif ($requiredAuthorization !== 'native' && $attachedId === null
					&& $unifiedLinkAccessService !== null
					&& $unifiedLinkAccessService->checkFreshUnifiedLinkSource($file, $userId)->canEdit())
				{
					$confirmations['unifiedLink'] = $this->unifiedLinkFingerprint($file);
				}
				else
				{
					return $this->refuse();
				}

				$visitor = $this->visitorFingerprint($actor, $userId);
				if ($visitor === null)
				{
					return $this->refuse();
				}
				$snapshot = [
					'schemaVersion' => 1,
					'objectId' => (int)$file->getRealObjectId(),
					'sourceType' => $source,
					'sourceId' => $sourceId,
					'attachedObjectId' => $attachedId,
					'versionId' => null,
					'visitorFingerprint' => $visitor,
					'confirmationFingerprints' => $confirmations,
				];
				if ($linkId !== null)
				{
					$revision = AccessRevocation::sourceRevisions([$linkId], DocumentStateRepository::hasOpenTransaction())[$linkId] ?? null;
					if ($revision === null)
					{
						if (Feature::isSharedEditingEnabled() || SharedEditingUsage::hasBeenUsed() || SharedEditingUsage::isPersisted())
						{
							return $this->refuse();
						}
						$revision = 0;
					}
					// Preserve existing immutable snapshots and fingerprints until the first actual source revocation.
					if ($revision > 0)
					{
						$snapshot['confirmationFingerprints']['sourceRevision'] = AccessRevocation::sourceRevisionFingerprint($revision);
					}
				}
				$expiresAt = $link?->getDeathTime();
				if ($expiresAt !== null)
				{
					$expiresAt = clone $expiresAt;
					$expiresAt->setTimeZone(new \DateTimeZone('UTC'));
				}

				return (new Result())->setData([
					'actorUserId' => $actor,
					'sourceType' => $source,
					'sourceId' => $sourceId,
					'contextHash' => hash('sha256', Json::encode($snapshot)),
					'context' => $snapshot,
					'expiresAt' => $expiresAt,
				]);
			},
		);
	}

	/** @param VerifiedAccess $access */
	public function revalidate(File $file, array $access): Result
	{
		$snapshot = $access['context'] ?? [];
		$type = $access['sourceType'] ?? null;
		$id = $access['sourceId'] ?? null;
		$confirmations = is_array($snapshot) && is_array($snapshot['confirmationFingerprints'] ?? null)
			? $snapshot['confirmationFingerprints']
			: [];
		$seedObjectIds = array_filter([
			(int)$file->getId(),
			(int)$file->getRealObjectId(),
			is_array($snapshot) && is_int($snapshot['objectId'] ?? null) ? (int)$snapshot['objectId'] : 0,
		]);
		$seedExternalLinkIds = in_array($type, ['external', 'unified'], true) && is_int($id) && $id > 0 ? [$id] : [];
		$seedAttachedIds = $type === 'attached' && is_int($id) && $id > 0 ? [$id] : [];
		$includeObjectPaths = $type !== 'external' || array_key_exists('classicLink', $confirmations);

		return DocumentStateRepository::withPreLockedObjects(
			$seedObjectIds,
			$seedExternalLinkIds,
			[],
			$seedAttachedIds,
			$includeObjectPaths,
			function () use ($file, $access, $snapshot, $type, $id): Result {
				if (DocumentStateRepository::shouldBypassRepeatableRead())
				{
					$file = $this->loadFile((int)$file->getId());
					if (!$file) { return $this->refuse(); }
				}
				if (!is_array($snapshot)
					|| !array_key_exists('attachedObjectId', $snapshot) || !array_key_exists('versionId', $snapshot)
					|| ($snapshot['attachedObjectId'] !== null
						&& (!is_int($snapshot['attachedObjectId']) || $snapshot['attachedObjectId'] <= 0))
					|| ($snapshot['schemaVersion'] ?? null) !== 1
					|| ($snapshot['objectId'] ?? null) !== (int)$file->getRealObjectId()
					|| ($snapshot['sourceType'] ?? null) !== $type || ($snapshot['sourceId'] ?? null) !== $id
					|| !in_array($type, ['internal', 'attached', 'unified', 'external'], true)
					|| !is_int($id) || ($snapshot['versionId'] ?? null) !== null
					|| !is_string($access['contextHash'] ?? null)
					|| !hash_equals($access['contextHash'], hash('sha256', Json::encode($snapshot)))
					|| (($access['expiresAt'] ?? null) instanceof DateTime && $access['expiresAt']->getTimestamp() <= time()))
				{
					return $this->refuse();
				}
				$attachedId = $snapshot['attachedObjectId'] ?? null;
				$linkSource = in_array($type, ['unified', 'external'], true);
				if ($linkSource && !SharedEditingSchema::hasSourceRevision()) { return $this->refuse(); }
				if (($type === 'internal' && ($id !== 0 || $attachedId !== null))
					|| ($type === 'attached' && ($id <= 0 || $id !== $attachedId))
					|| ($linkSource && $id <= 0))
				{
					return $this->refuse();
				}
				$opening = null;
				$unifiedLinkAccessService = null;
				$confirmations = $snapshot['confirmationFingerprints'] ?? [];
				if (!is_array($confirmations))
				{
					return $this->refuse();
				}
				if (array_key_exists('unifiedLink', $confirmations))
				{
					if (!in_array($type, ['internal', 'unified'], true))
					{
						return $this->refuse();
					}
					$unifiedLinkAccessService = ServiceLocator::getInstance()->get(UnifiedLinkAccessService::class);
				}
				if (array_key_exists('classicLink', $confirmations))
				{
					if ($type !== 'external' || $confirmations['classicLink'] !== $this->classicLinkFingerprint($file, $id))
					{
						return $this->refuse();
					}
					$unifiedLinkAccessService = ServiceLocator::getInstance()->get(UnifiedLinkAccessService::class);
				}
				if ($type === 'unified')
				{
					if (DocumentStateRepository::shouldBypassRepeatableRead())
					{
						$discoveredRows = DocumentStateRepository::discoverRowsForFreshRead(
							ExternalLinkTable::query()->setSelect(['ID', 'CODE'])->where('ID', $id),
						);
						$code = (string)($discoveredRows[0]['CODE'] ?? '');
						$opening = $code !== '' ? ShortLinkOpening::resolve($code) : null;
					}
					else
					{
						$link = $this->loadExternalLink($id);
						$opening = $link ? ShortLinkOpening::resolve((string)$link->getShortCode()) : null;
					}
					if ($opening === null || (int)$opening->getExternalLink()->getId() !== $id)
					{
						return $this->refuse();
					}
					if ($opening->allowsEditBySource() && array_key_exists('unifiedLink', $confirmations))
					{
						return $this->refuse();
					}
				}
				$requiresOwnEditAuthorization = !$linkSource || ($type === 'unified' && !$opening->allowsEditBySource());
				$requiredAuthorization = $requiresOwnEditAuthorization
					? (array_key_exists('unifiedLink', $confirmations) ? 'internal-unified' : 'native')
					: null;
				$result = $this->verifyContext(new DocumentSessionContext(
					(int)$file->getRealObjectId(), $attachedId, $linkSource ? $id : null,
				), $opening, 'edit', $unifiedLinkAccessService, $requiredAuthorization, $file);
				if (!$result->isSuccess())
				{
					return $result;
				}
				$fresh = $result->getData();
				if ((array_key_exists('unifiedLink', $confirmations)
						&& $confirmations['unifiedLink'] !== ($fresh['context']['confirmationFingerprints']['unifiedLink'] ?? null))
					|| ($access['actorUserId'] ?? null) !== $fresh['actorUserId']
					|| ($snapshot['visitorFingerprint'] ?? null) !== $fresh['context']['visitorFingerprint'])
				{
					return $this->refuse();
				}

				return $result;
			},
		);
	}

	private function loadFile(int $objectId): ?File
	{
		if ($objectId <= 0) { return null; }
		if (!DocumentStateRepository::shouldBypassRepeatableRead()) { return File::loadById($objectId); }
		$discovered = DocumentStateRepository::discoverRowsForFreshRead(
			ObjectTable::query()->setSelect(['ID', 'REAL_OBJECT_ID'])->where('ID', $objectId),
		);
		if ($discovered === null) { return null; }
		$candidateIds = [$objectId => $objectId];
		foreach ($discovered as $row)
		{
			$realId = (int)($row['REAL_OBJECT_ID'] ?? 0);
			if ($realId > 0) { $candidateIds[$realId] = $realId; }
		}
		ksort($candidateIds, SORT_NUMERIC);
		$locked = [];
		foreach (DocumentStateRepository::lockObjectsForShare(array_values($candidateIds), ['*']) as $row)
		{
			$locked[(int)$row['ID']] = $row;
		}
		$row = $locked[$objectId] ?? null;
		$file = $row ? \Bitrix\Disk\BaseObject::buildFromArray($row) : null;
		if (!$file instanceof File || $file->isDeleted()) { return null; }
		if ($file->isLink())
		{
			$realRow = $locked[(int)$file->getRealObjectId()] ?? null;
			$real = $realRow ? \Bitrix\Disk\BaseObject::buildFromArray($realRow) : null;
			if (!$real instanceof File || $real->isDeleted() || $real->isLink()) { return null; }
			$file->primeRealObject($real);
		}
		return $file;
	}

	private function loadExternalLink(int $id): ?ExternalLink
	{
		if (!DocumentStateRepository::shouldBypassRepeatableRead()) { return ExternalLink::loadById($id); }
		$row = DocumentStateRepository::fetchForShare(ExternalLinkTable::query()->setSelect(['*'])->where('ID', $id));
		return $row ? ExternalLink::buildFromArray($row) : null;
	}

	private function loadAttachedObject(int $id): ?AttachedObject
	{
		if (!DocumentStateRepository::shouldBypassRepeatableRead()) { return AttachedObject::loadById($id); }
		$discovered = DocumentStateRepository::discoverRowsForFreshRead(
			AttachedObjectTable::query()->setSelect(['ID', 'OBJECT_ID'])->where('ID', $id),
		);
		$objectId = (int)($discovered[0]['OBJECT_ID'] ?? 0);
		if ($objectId <= 0) { return null; }
		$file = $this->loadFile($objectId);
		if (!$file) { return null; }
		$row = DocumentStateRepository::fetchForShare(AttachedObjectTable::query()->setSelect(['*'])->where('ID', $id));
		if (!$row || (int)$row['OBJECT_ID'] !== (int)$file->getId()) { return null; }
		$row['OBJECT'] = $file;
		return AttachedObject::buildFromArray($row);
	}

	private function unifiedLinkFingerprint(File $file): string
	{
		return UnifiedLinkSourceRevision::fingerprint($file, DocumentStateRepository::hasOpenTransaction());
	}

	private function classicLinkFingerprint(File $file, int $linkId): string
	{
		return hash('sha256', 'classic-link:' . $file->getRealObjectId() . ':' . $linkId);
	}

	/** @param VerifiedAccess $access */
	public function resolveOwner(File $file, array $access, ?int $inheritedOwnerId): ?int
	{
		$file = File::loadById((int)$file->getRealObjectId());
		if (!$file || $file->isDeleted())
		{
			return null;
		}
		$snapshot = $access['context'] ?? [];
		if (!is_array($snapshot) || ($snapshot['objectId'] ?? null) !== (int)$file->getRealObjectId()
			|| (($snapshot['attachedObjectId'] ?? null) !== null
				&& !is_int($snapshot['attachedObjectId'])))
		{
			return null;
		}
		$link = in_array($access['sourceType'] ?? null, ['unified', 'external'], true)
			? ExternalLink::loadById((int)$access['sourceId']) : null
		;
		$context = new DocumentSessionContext((int)$file->getRealObjectId(), $snapshot['attachedObjectId'] ?? null);
		$session = $this->makeSession($file, $context, 0);
		$userId = $this->getCurrentUserId();
		$visitor = $this->visitorFingerprint($this->getActorUserId($userId), $userId);
		$opener = $visitor !== null && is_string($snapshot['visitorFingerprint'] ?? null)
			&& hash_equals($snapshot['visitorFingerprint'], $visitor) ? $userId : null
		;
		foreach (array_unique([
			$opener, $link?->getCreatedBy(), $file->getCreatedBy(), $inheritedOwnerId,
		]) as $id)
		{
			$id = (int)$id;
			if ($id <= 0 || !UserTable::getById($id)->fetch())
			{
				continue;
			}
			$security = $this->getFreshSecurityContext($file, $id);
			if ($security && ($file->canRead($security) || $session->canRead($security)))
			{
				return $id;
			}
		}

		return null;
	}

	public function hasShortLinkEditAccess(
		File $file,
		int $externalLinkId,
		int $actorUserId,
		?string $requiredAuthorization = null,
	): bool
	{
		return DocumentStateRepository::withPreLockedObjects(
			[(int)$file->getId(), (int)$file->getRealObjectId()],
			[$externalLinkId],
			[],
			[],
			true,
			function () use ($file, $externalLinkId, $actorUserId, $requiredAuthorization): bool {
				if (DocumentStateRepository::shouldBypassRepeatableRead())
				{
					$discovered = DocumentStateRepository::discoverRowsForFreshRead(
						ExternalLinkTable::query()->setSelect(['ID', 'CODE'])->where('ID', $externalLinkId),
					);
					$code = (string)($discovered[0]['CODE'] ?? '');
					$opening = $code !== '' ? ShortLinkOpening::resolve($code) : null;
				}
				else
				{
					$link = ExternalLink::loadById($externalLinkId);
					$opening = $link ? ShortLinkOpening::resolve((string)$link->getShortCode()) : null;
				}
				if ($opening === null || (int)$opening->getExternalLink()->getId() !== $externalLinkId)
				{
					return false;
				}
				$storage = $opening->getFile()->getStorage();
				if ($storage === null)
				{
					return false;
				}
				ServiceLocator::getInstance()->get('disk.storageRuntimeCache')->remove($storage);

				$isGuest = $actorUserId <= 0
					|| (UserTable::getById($actorUserId)->fetch()['EXTERNAL_AUTH_ID'] ?? null) === DocumentEditorUser::EXTERNAL_AUTH_ID;
				$session = $this->makeSession($file, new DocumentSessionContext((int)$file->getRealObjectId()), $isGuest ? 0 : $actorUserId);
				$revalidated = $opening->revalidateForEditor($session, 'edit', $isGuest, $isGuest ? 0 : $actorUserId);
				if ($revalidated === null)
				{
					return false;
				}
				if (!$revalidated->allowsEditBySource())
				{
					if ($isGuest)
					{
						return false;
					}
					$context = new DocumentSessionContext((int)$file->getId(), null, $externalLinkId);
					if ($requiredAuthorization === 'native')
					{
						return $this->hasNativeEditAccess($file, $context, $actorUserId);
					}
					if ($requiredAuthorization === 'internal-unified')
					{
						return ServiceLocator::getInstance()
							->get(UnifiedLinkAccessService::class)
							->checkFreshUnifiedLinkSource($file, $actorUserId)
							->canEdit();
					}

					return $this->hasNativeEditAccess($file, $context, $actorUserId)
						|| ServiceLocator::getInstance()
							->get(UnifiedLinkAccessService::class)
							->checkFreshUnifiedLinkSource($file, $actorUserId)
							->canEdit();
				}

				return true;
			},
		);
	}

	public function hasNativeEditAccess(File $file, DocumentSessionContext $context, int $userId): bool
	{
		return DocumentStateRepository::withPreLockedObjects(
			[(int)$file->getId(), (int)$file->getRealObjectId(), (int)$context->getObjectId()],
			[],
			[],
			$context->getAttachedObjectId() ? [(int)$context->getAttachedObjectId()] : [],
			true,
			function () use ($file, $context, $userId): bool {
				$fresh = DocumentStateRepository::shouldBypassRepeatableRead();
				if ($fresh)
				{
					$file = $this->loadFile((int)$file->getId());
					if (!$file) { return false; }
				}
				if ($userId <= 0 || $file->isDeleted() || (int)$file->getRealObjectId() !== $context->getObjectId())
				{
					return false;
				}
				$attachedId = $context->getAttachedObjectId();
				$attached = $attachedId !== null ? $this->loadAttachedObject($attachedId) : null;
				if ($attachedId !== null && (!$attached || $attached->isSpecificVersion()
					|| (int)$attached->getFile()?->getRealObjectId() !== (int)$file->getRealObjectId()))
				{
					return false;
				}
				$security = $this->getFreshSecurityContext($file, $userId);
				if ($fresh)
				{
					return $security !== null && (($attached !== null && $attached->canUpdate($userId))
						|| $security->canUpdate((int)$file->getRealObjectId()));
				}
				$nativeContext = new DocumentSessionContext((int)$file->getRealObjectId(), $attachedId);

				return $security !== null && $this->makeSession($file, $nativeContext, $userId)->canEdit($security);
			},
		);
	}

	private function getFreshSecurityContext(File $file, int $userId): ?\Bitrix\Disk\Security\SecurityContext
	{
		// A cached provider can retain permissions revoked by another worker during an HTTP request.
		return $file->getStorage()?->getProxyType()->getSecurityContextByUser($userId);
	}

	private function makeSession(File $file, DocumentSessionContext $context, int $userId): DocumentSession
	{
		return DocumentSession::buildFromArray([
			'OBJECT_ID' => (int)$file->getRealObjectId(), 'VERSION_ID' => null,
			'USER_ID' => $userId, 'TYPE' => DocumentSession::TYPE_EDIT,
			'CONTEXT' => $context->toJson(), 'SERVICE' => DocumentService::Vibeoffice->value,
		]);
	}

	/** Identifies a caller without treating an unavailable source as somebody else's attempt. */
	public function isCurrentVisitor(array $access): bool
	{
		$userId = $this->getCurrentUserId();
		$actor = $this->getActorUserId($userId);

		return ($access['actorUserId'] ?? null) === $actor
			&& ($access['context']['visitorFingerprint'] ?? null) === $this->visitorFingerprint($actor, $userId);
	}

	private function getCurrentUserId(): int
	{
		$userId = (int)CurrentUser::get()->getId();

		return $userId > 0 && UserTable::getById($userId)->fetch() ? $userId : 0;
	}

	private function getActorUserId(int $userId): ?int
	{
		if ($userId === 0)
		{
			return null;
		}

		return DocumentEditorUser::isCurrentUserDocumentEditor() || User::loadById($userId)?->isIntranetUser()
			? $userId : null
		;
	}

	private function visitorFingerprint(?int $actor, int $userId): ?string
	{
		$sessionId = Application::getInstance()->getKernelSession()->getId();
		if ($userId === 0 && $sessionId === '')
		{
			return null;
		}

		return hash('sha256', 'disk.vo.visitor.v1|' . $userId . '|' . (int)$actor . '|' . $sessionId);
	}

	private function refuse(): Result
	{
		return (new Result())->addError(new Error('The editing source is unavailable.', 'vibeoffice_access_denied'));
	}
}
