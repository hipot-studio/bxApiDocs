<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DocumentPresence;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionContext;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\Models\GuestUser;
use Bitrix\Disk\File;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Who has the object open at the moment the header starts (ALG-02).
 *
 * The two sources differ on purpose: active document sessions are the only place an anonymous visitor
 * of a public link is recorded, while the live roster of {@see PresenceService} holds accounts only.
 */
class InitialRosterService
{
	public const ERROR_ROSTER_UNAVAILABLE = 'PRESENCE_NOT_AVAILABLE';

	/**
	 * A session row carries no sign of liveness: it is put out by a callback of the editor alone, so a
	 * tab closed past it stays active forever. The roster therefore counts an opening only while it is
	 * young enough to still be one.
	 *
	 * The width is a guess and not a fact - the row is written once, at the opening, and its time of
	 * creation is never refreshed, so nothing here tells a tab still open from one closed long ago. The
	 * guess is made against the ceiling below, which counts live tabs and nothing else: the wider this
	 * window, the more of the roster is made of openings that have ended, and the sooner a document read
	 * during one day by many people stops showing the block to anybody at all. Half an hour covers an
	 * ordinary reading of a document - the stretch an anonymous visitor of a public link, the only
	 * participant holding no live registration whatsoever, is kept by this source alone.
	 */
	public const FRESHNESS_WINDOW = 30 * 60;

	/**
	 * Every candidate costs a rights check of its own, so their number must not follow the openings a
	 * document has gathered. The ceiling is the one the live roster already has: it holds no more
	 * registrations than this per scope, so no wider roster ever survives the first live event anyway.
	 */
	public const MAX_PARTICIPANTS = PresenceService::MAX_REGISTRATIONS_PER_SCOPE;

	public function __construct(
		private readonly PresenceService $presenceService,
		private readonly PresenceAccessService $presenceAccessService,
	)
	{
	}

	/**
	 * @param string $accessType PresenceAccessService::ACCESS_TYPE_INTERNAL | ACCESS_TYPE_UNIFIED
	 * @return Result data keys `revision` and `participants`
	 */
	public function collect(DocumentSession $documentSession, int $userId, string $accessType): Result
	{
		$result = new Result();

		try
		{
			$file = $documentSession->getObject();
			if (!$file instanceof File)
			{
				return $result->addError($this->getUnavailableError());
			}

			$sessionUserIds = $this->listSessionUserIds($file, $documentSession, $accessType);
			$namedUserIds = array_values(array_filter(
				$sessionUserIds,
				static fn(int $sessionUserId): bool => $sessionUserId > 0,
			));
			$hasAnonymous = count($namedUserIds) < count($sessionUserIds);

			$scope = $this->presenceAccessService->resolveAuthorizedScope($documentSession, $userId, $accessType);
			$snapshot = $scope === null
				? ['revision' => 0, 'userIds' => [], 'confirmingUserIds' => []]
				: $this->presenceService->snapshot($scope)
			;
			$confirmingUserIds = array_flip($snapshot['confirmingUserIds']);
			$namedUserIds = array_values(array_unique(array_merge($namedUserIds, $snapshot['userIds'])));
			if (count($namedUserIds) > self::MAX_PARTICIPANTS)
			{
				// A roster this wide is refused rather than cut down to the ceiling: the header hides the
				// block on a refusal, while a cut down roster would show a stack and a counter of everybody
				// else that both understate the truth.
				return $result->addError($this->getUnavailableError(
					'Presence roster is wider than the header can show.',
				));
			}

			$participants = array_map(
				// a participant the live roster does not call confirming - one who holds no registration at
				// all among them - is never hidden by silence, so the flag is `false` unless it is known
				static fn(array $participant): array => $participant + [
					'isGuest' => false,
					'isConfirming' => isset($confirmingUserIds[$participant['id']]),
				],
				$this->presenceAccessService->resolveParticipants(
					$this->presenceAccessService->filterReadable(
						$namedUserIds,
						$file,
						$this->resolveScopeAttachedObject($documentSession, $userId),
						$accessType,
					),
				),
			);
			if ($hasAnonymous)
			{
				// Several anonymous visitors of one public link are a single guest: the roster tells them
				// apart no better than the sessions do.
				$participants[] = [
					'id' => GuestUser::GUEST_USER_ID,
					'name' => (string)GuestUser::create()->getName(),
					'avatar' => '',
					'isGuest' => true,
					'isConfirming' => false,
				];
			}
		}
		catch (\Throwable $e)
		{
			$this->logUnforeseenFailure($e);

			return $result->addError($this->getUnavailableError());
		}

		return $result->setData([
			'revision' => $snapshot['revision'],
			'participants' => $participants,
		]);
	}

	/**
	 * The opening the roster is asked for joins it apart from the window: an active row is reused instead
	 * of made anew and its time of creation is never refreshed, so an opening happening right now may well
	 * be older than the window. Whoever is looking at the document is present by definition; the rights of
	 * that user are checked further on together with everybody else.
	 *
	 * @param string $accessType PresenceAccessService::ACCESS_TYPE_INTERNAL | ACCESS_TYPE_UNIFIED
	 * @return list<int>
	 */
	private function listSessionUserIds(
		File $file,
		DocumentSession $documentSession,
		string $accessType,
	): array
	{
		$userIds = array_map(
			static fn(array $row): int => (int)$row['USER_ID'],
			$this->fetchActiveSessions(
				$this->buildOpeningFilter($file, $documentSession, $accessType),
				self::MAX_PARTICIPANTS + 1,
			),
		);
		$userIds[] = $documentSession->getUserId();

		return array_values(array_unique($userIds));
	}

	/**
	 * The openings this source counts are the ones sharing the key of the live roster: {@see
	 * PresenceAccessService::buildScope()} keys it by the real object, the version and the access type.
	 * A source narrowing by anything less would hand the header participants the live channel never
	 * names - a reader of a historical version among those looking at the current content, a visitor of
	 * a link among those inside the portal - and the first live event would drop them again without
	 * anybody having left.
	 *
	 * The engine of the opening and its external hash are deliberately left out: two people who opened
	 * one document with different editors are looking at the same thing, and both viewing and editing
	 * sessions count.
	 *
	 * The attached object of the key stays out for want of a column - it lives inside the CONTEXT of a
	 * row. It narrows the roster all the same, one step further on: every candidate is asked for the
	 * right on the attached object of the requester {@see PresenceAccessService::filterReadable()}.
	 *
	 * @param string $accessType PresenceAccessService::ACCESS_TYPE_INTERNAL | ACCESS_TYPE_UNIFIED
	 * @return array<string, mixed>
	 */
	private function buildOpeningFilter(
		File $file,
		DocumentSession $documentSession,
		string $accessType,
	): array
	{
		$versionId = (int)$documentSession->getVersionId();
		$filter = [
			'=OBJECT_ID' => (int)$file->getRealObjectId(),
			'=STATUS' => DocumentSessionTable::STATUS_ACTIVE,
			// A row of the current content names no version, so the absence is asked for and not a zero.
			'=VERSION_ID' => $versionId > 0 ? $versionId : null,
			'>=CREATE_TIME' => $this->getFreshnessThreshold(),
		];

		// The column mirrors the link named by the context of a row {@see DocumentSession::buildFromArray()},
		// and the access type of an opening is resolved from that very link
		// {@see \Bitrix\Disk\Internal\Service\DocumentHeader\OpeningContextFactory::resolveAccessType()}.
		if ($accessType === PresenceAccessService::ACCESS_TYPE_UNIFIED)
		{
			$filter['!=EXTERNAL_LINK_ID'] = null;
		}
		else
		{
			$filter['=EXTERNAL_LINK_ID'] = null;
		}

		return $filter;
	}

	/**
	 * An active row older than the window is a tab closed past the callback of the editor rather than an
	 * opening.
	 *
	 * The limit counts users rather than rows, hence the grouping: one user holds as many openings of a
	 * document as they had editors and reopenings. Grouping by the user also follows the index the table
	 * already carries on the object and the user, so it asks for no sorting of its own.
	 *
	 * @param array<string, mixed> $filter The openings of one roster, {@see self::buildOpeningFilter()}.
	 * @param int $limit One user over the ceiling of the roster, so that a wider one is told apart.
	 * @return array<int, array{USER_ID: int|string|null}>
	 */
	protected function fetchActiveSessions(array $filter, int $limit): array
	{
		return DocumentSessionTable::getList([
			'select' => ['USER_ID'],
			'filter' => $filter,
			'group' => ['USER_ID'],
			'limit' => $limit,
		])->fetchAll();
	}

	/**
	 * The time of creation is never refreshed, so an opening of somebody else lasting longer than the
	 * window leaves the roster: the error is on the side of the smaller roster rather than of the
	 * everlasting one.
	 */
	protected function getFreshnessThreshold(): DateTime
	{
		return DateTime::createFromTimestamp(time() - self::FRESHNESS_WINDOW);
	}

	/**
	 * The attached object of an opening narrows the roster to whoever reads that object, so it keys the
	 * roster only for a requester who reads it too - the very right
	 * {@see PresenceAccessService::resolveAuthorizedScope()} demands of the live roster. A row of the
	 * current user may still name an attached object of somebody else: reading of an opening is granted by
	 * either that object or the object in its storage, and {@see DocumentSession::forkForUser()} hands the
	 * context of the row it was forked from over to the new one.
	 *
	 * Without that right the roster is built for the file alone, which is the area the requester was let
	 * into anyway; a lawful reader of the attached object keeps the roster of it.
	 */
	private function resolveScopeAttachedObject(DocumentSession $documentSession, int $userId): ?AttachedObject
	{
		$attachedObject = $this->getAttachedObject($documentSession);
		if (!$attachedObject || $userId <= 0)
		{
			return null;
		}

		return $this->presenceAccessService->canReadAttachedObject($attachedObject, $userId)
			? $attachedObject
			: null
		;
	}

	/**
	 * A session row may carry an unreadable CONTEXT; that is not a reason to refuse the whole roster.
	 *
	 * The access service resolves it instead of the context itself so that the object of this opening is
	 * loaded once per request: {@see PresenceAccessService::resolveAuthorizedScope()} asks the same session
	 * about the same attached object right before this.
	 */
	protected function getAttachedObject(DocumentSession $documentSession): ?AttachedObject
	{
		try
		{
			$context = $documentSession->getContext();
		}
		catch (\Throwable)
		{
			return null;
		}

		return $context instanceof DocumentSessionContext
			? $this->presenceAccessService->resolveAttachedObject($context)
			: null
		;
	}

	/**
	 * The header hides the whole block on a refusal, so a failure turned into one and nothing else would
	 * look exactly like "nobody else is here" and leave such a report with no trace to diagnose it by.
	 */
	protected function logUnforeseenFailure(\Throwable $e): void
	{
		Application::getInstance()->getExceptionHandler()->writeToLog($e);
	}

	private function getUnavailableError(string $message = 'Presence roster cannot be built.'): Error
	{
		return new Error($message, self::ERROR_ROSTER_UNAVAILABLE);
	}
}
