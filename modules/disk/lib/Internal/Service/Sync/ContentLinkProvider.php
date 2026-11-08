<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync;

use Bitrix\Disk\File;
use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\ContentLinkDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\SyncAccessDeniedException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\SyncObjectNotFoundException;
use Bitrix\Disk\Internal\Service\Sync\Card\ContentAvailability;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\Type\DateTime;

/**
 * The grant a client is handed instead of a session, and the link that carries it.
 *
 * The contour serves no bytes. What it does is turn the download identifier of a card into a link to
 * the download mechanism Disk already has, signed so that it can be presented by a caller that has no
 * portal session, and dated so that it stops working on its own.
 *
 * Both halves of the grant live here on purpose - what is written into a link and what is read back
 * out of one are one format, and a format split across two classes drifts the first time either side
 * is touched.
 */
final class ContentLinkProvider
{
	/**
	 * The method the link points at. It is answered by
	 * {@see \Bitrix\Disk\Infrastructure\Rest\Sync\ContentDownload}, which the module publishes in the
	 * scope every caller already has.
	 *
	 * That scope is not a convenience. A link minted inside a V3 method can carry no other: the V3
	 * server names no scope of its own ({@see \CRestApiServer} never sets it), so the token
	 * {@see \CRestUtil::getSpecialUrl()} builds is always a token of the global scope - which is why
	 * the platform's own deferred batch reaches its result file the same way. Asking the caller for
	 * the classic scope of Disk is exactly what this contour exists not to do, so the grant below is
	 * what authorises the download, and the rights of Disk are asked again when it is presented.
	 */
	public const DOWNLOAD_METHOD = 'disk.sync.content.download';

	/**
	 * How long a link is accepted for.
	 *
	 * An hour: long enough for a client to fetch a burst of files it has just asked links for, to
	 * retry a large one over a poor connection, and to survive a queue that stalled; short enough that
	 * a link left in a proxy log or in the history of a browser is worthless by the time anybody reads
	 * it. Longer would not buy a client anything - a link is cheap to ask for again, and asking again
	 * re-checks the rights, which is the point.
	 */
	private const LINK_TTL_SECONDS = 3600;

	private const SIGNATURE_SALT = 'disk.sync.content';

	private const PARAMETER_USER_ID = 'userId';
	private const PARAMETER_OBJECT_ID = 'objectId';
	private const PARAMETER_EXPIRES_AT = 'expiresAt';
	private const PARAMETER_SIGNATURE = 'signature';

	public function __construct(
		private readonly ContentAvailability $availability = new ContentAvailability(),
		private readonly Signer $signer = new Signer(),
	)
	{
	}

	/**
	 * A link to the bytes of the object the identifier names, for the caller the pair belongs to.
	 *
	 * Everything the identifier can be wrong about is answered by one refusal: a shape this portal
	 * never issues, a kind of resource it does not serve yet, an object the caller cannot see, an
	 * object whose content the contour calls unavailable. They are one answer because they are one
	 * fact from where the caller stands - "this names nothing you may have" - and because two answers
	 * would let the identifiers of a portal be probed apart.
	 *
	 * The rule of availability is asked for rather than restated: it is the one the cards are built
	 * by, so a client is never offered content here that a card would not have offered, nor refused
	 * content a card did offer.
	 *
	 * A caller the portal cannot name at all gets no link either: the link carries the credentials of
	 * the application that asked for it, and there is nothing to sign one for without them.
	 *
	 * @throws SyncAccessDeniedException the identifier names nothing this caller may fetch
	 * @throws SyncObjectNotFoundException the object is there and its content is not
	 */
	public function issue(int $userId, string $downloadId, ?\CRestServer $server): ContentLinkDto
	{
		$appId = CallingApplication::nameOf($server);
		$objectId = $this->objectIdOf($downloadId);
		if (
			$server === null
			|| $appId === null
			|| $objectId === null
			|| $this->availability->findAvailable($userId, [$objectId]) === []
		)
		{
			throw new SyncAccessDeniedException();
		}

		$file = File::loadById($objectId);
		if (!$file instanceof File)
		{
			throw new SyncAccessDeniedException();
		}

		// Visibility has already been proved by this point, so telling the caller that the bytes are
		// gone says nothing about the objects of the portal it did not already know.
		if ((int)$file->getFileId() <= 0)
		{
			throw new SyncObjectNotFoundException();
		}

		$expiresAt = time() + self::LINK_TTL_SECONDS;

		$link = new ContentLinkDto();
		$link->url = \CRestUtil::getSpecialUrl(
			self::DOWNLOAD_METHOD,
			$this->grantFor($userId, $appId, $objectId, $expiresAt),
			$server,
		);
		$link->expiresAt = DateTime::createFromTimestamp($expiresAt);

		return $link;
	}

	/**
	 * The object a presented grant opens, or null when it opens nothing.
	 *
	 * Both halves of the pair the grant was issued to are named by the request itself and neither is
	 * taken from what the request carries: the user by the authorisation, the application by the
	 * credentials that authorised it. A grant is issued to one pair and must not become a way for a
	 * second caller of the same user to fetch what the first was allowed - the method it is presented
	 * at lives in the scope every caller has, so any other application of that user, and any incoming
	 * webhook of theirs, could otherwise spend a grant that leaked out of a log or a link.
	 *
	 * Nothing here decides whether the object may be read. That is asked at the download itself, by
	 * the same code that answers every other download of Disk, so a right taken away after a link was
	 * minted is a refused download and not an open one.
	 */
	public function objectIdOfGrant(array $query, int $userId, ?\CRestServer $server): ?int
	{
		$appId = CallingApplication::nameOf($server);
		$grantedUserId = (int)($query[self::PARAMETER_USER_ID] ?? 0);
		$objectId = (int)($query[self::PARAMETER_OBJECT_ID] ?? 0);
		$expiresAt = (int)($query[self::PARAMETER_EXPIRES_AT] ?? 0);
		$signature = (string)($query[self::PARAMETER_SIGNATURE] ?? '');

		if (
			$userId <= 0
			|| $appId === null
			|| $grantedUserId !== $userId
			|| $objectId <= 0
			|| $expiresAt < time()
		)
		{
			return null;
		}

		try
		{
			$state = $this->signedState($grantedUserId, $appId, $objectId, $expiresAt);
			if (!$this->signer->validate($state, $signature, self::SIGNATURE_SALT))
			{
				return null;
			}
		}
		catch (BadSignatureException)
		{
			// The value arrives from a query string, so it may be anything at all: a signature that is
			// not even well formed is a refusal like any mismatch and not a fault of the portal.
			return null;
		}

		return $objectId;
	}

	/**
	 * The application is signed and not carried: a name inside the link would be a claim the presenter
	 * writes, and the whole point of it is that it is read off the credentials instead.
	 *
	 * @return array<string, int|string>
	 */
	private function grantFor(int $userId, string $appId, int $objectId, int $expiresAt): array
	{
		return [
			self::PARAMETER_USER_ID => $userId,
			self::PARAMETER_OBJECT_ID => $objectId,
			self::PARAMETER_EXPIRES_AT => $expiresAt,
			self::PARAMETER_SIGNATURE => $this->signer->getSignature(
				$this->signedState($userId, $appId, $objectId, $expiresAt),
				self::SIGNATURE_SALT,
			),
		];
	}

	/**
	 * The name of the application goes last because it is the one part of free length: put anywhere
	 * else, a name carrying the separator could be read as a different state of the same shape.
	 */
	private function signedState(int $userId, string $appId, int $objectId, int $expiresAt): string
	{
		return $userId . '|' . $objectId . '|' . $expiresAt . '|' . $appId;
	}

	/**
	 * The object an identifier of a card names, or null when the identifier is not one this portal
	 * issues.
	 *
	 * Read by round trip rather than by a grammar written down a second time: the identifier is built
	 * by {@see ContentAvailability::downloadIdOf()}, and anything that does not come back out of it
	 * character for character is not an identifier of this contour - a kind of resource stage 3 will
	 * add, a number dressed up by hand, a string from somewhere else entirely.
	 */
	private function objectIdOf(string $downloadId): ?int
	{
		if (preg_match('#:(\d+)$#D', $downloadId, $matches) !== 1)
		{
			return null;
		}

		$objectId = (int)$matches[1];

		return $objectId > 0 && $this->availability->downloadIdOf($objectId) === $downloadId ? $objectId : null;
	}
}
