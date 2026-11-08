<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Comment;

use Bitrix\Disk\Document\Flipchart\Configuration;
use Bitrix\Disk\Document\Flipchart\JwtService;
use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\GuestUser;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Result;
use Bitrix\Main\Web\JWT;

/**
 * Turns the Bearer JWT of a comments request into an {@see AccessContext}.
 *
 * The order of refusals is the one of the contract: token and required claims (401), then the
 * `access_level` claim, the board itself and the current rights on it (403). Unlike annotations,
 * comments have no context without a board: a historical version, an unknown and a deleted board
 * are all refused here, and only a request with a live board reaches the operation.
 *
 * The protected methods are the seams to the portal: token verification, file, session, external
 * link and security context. Tests stand them in; production keeps the defaults.
 */
class ContextResolver
{
	private const CLAIM_USER_ID = 'user_id';
	private const CLAIM_DOCUMENT_ID = 'document_id';
	private const CLAIM_ACCESS = 'access_level';
	private const CLAIM_SESSION_ID = 'session_id';

	private const ERROR_UNAUTHORIZED = 'unauthorized';
	private const ERROR_FORBIDDEN = 'forbidden';

	private const BEARER_PATTERN = '/^Bearer ([A-Za-z0-9\-._~+\/]+=*)$/D';
	private const REGISTERED_USER_ID_PATTERN = '/^[1-9][0-9]{0,18}$/D';
	private const DOCUMENT_ID_PATTERN = '/^(?:.*-)?([1-9][0-9]{0,9})(?:\.([1-9][0-9]{0,9}))?$/D';

	/**
	 * The level an operation needs, told by the operation itself: the HTTP method it travels as says
	 * nothing about it. A logical read carried by POST stays a read and stays open to a guest, and a
	 * write keeps its level whatever the method is. An operation missing here is a mistake of ours,
	 * not of the caller, and is refused as one.
	 */
	private const REQUIRED_LEVEL_BY_OPERATION = [
		RequestValidator::OPERATION_SET_IMPORTANT => AccessContext::ACCESS_WRITE,
		RequestValidator::OPERATION_SET_PINNED => AccessContext::ACCESS_WRITE,
		RequestValidator::OPERATION_GET_THREADS => AccessContext::ACCESS_READ,
		RequestValidator::OPERATION_GET_THREAD_META => AccessContext::ACCESS_READ,
		RequestValidator::OPERATION_GET_THREAD_COMMENTS => AccessContext::ACCESS_READ,
		RequestValidator::OPERATION_COUNT_COMMENTS => AccessContext::ACCESS_READ,
		RequestValidator::OPERATION_GET_MAX_COMMENT_ID => AccessContext::ACCESS_READ,
		RequestValidator::OPERATION_GET_COMMENT => AccessContext::ACCESS_READ,
		RequestValidator::OPERATION_MARK_READ => AccessContext::ACCESS_READ,
		RequestValidator::OPERATION_MARK_UNREAD => AccessContext::ACCESS_READ,
		RequestValidator::OPERATION_MARK_ALL_READ => AccessContext::ACCESS_READ,
		RequestValidator::OPERATION_IS_READ_ALL => AccessContext::ACCESS_READ,
		RequestValidator::OPERATION_GET_READ_STATUSES => AccessContext::ACCESS_READ,
		RequestValidator::OPERATION_GET_MENTIONS => AccessContext::ACCESS_READ,
		RequestValidator::OPERATION_GET_ATTACHMENT => AccessContext::ACCESS_READ,
		RequestValidator::OPERATION_ADD_THREAD => AccessContext::ACCESS_WRITE,
		RequestValidator::OPERATION_UPDATE_THREAD_COORDINATES => AccessContext::ACCESS_WRITE,
		RequestValidator::OPERATION_DELETE_THREAD => AccessContext::ACCESS_WRITE,
		RequestValidator::OPERATION_RESTORE_THREAD => AccessContext::ACCESS_WRITE,
		RequestValidator::OPERATION_RESOLVE_THREAD => AccessContext::ACCESS_WRITE,
		RequestValidator::OPERATION_UNRESOLVE_THREAD => AccessContext::ACCESS_WRITE,
		RequestValidator::OPERATION_ADD_COMMENT => AccessContext::ACCESS_WRITE,
		RequestValidator::OPERATION_UPDATE_COMMENT => AccessContext::ACCESS_WRITE,
		RequestValidator::OPERATION_DELETE_COMMENT => AccessContext::ACCESS_WRITE,
		RequestValidator::OPERATION_RECOVER_COMMENT => AccessContext::ACCESS_WRITE,
		RequestValidator::OPERATION_ADD_REACTION => AccessContext::ACCESS_WRITE,
		RequestValidator::OPERATION_REMOVE_REACTION => AccessContext::ACCESS_WRITE,
		RequestValidator::OPERATION_UPLOAD_ATTACHMENT => AccessContext::ACCESS_WRITE,
	];

	/**
	 * @param string $operation One of the {@see RequestValidator} OPERATION_* constants.
	 *
	 * @return Result On success `getData()['context']` holds the {@see AccessContext}.
	 */
	public function resolve(string $operation, HttpRequest $request): Result
	{
		$result = new Result();

		$token = $this->extractBearerToken($request);
		if ($token === null)
		{
			return $result->addError(self::unauthorized('Bearer token is required.'));
		}

		$claims = $this->decodeToken($token);
		if ($claims === null)
		{
			return $result->addError(self::unauthorized('Token is not valid.'));
		}

		$userId = self::readUserId($claims);
		if ($userId === null)
		{
			return $result->addError(self::unauthorized('Claim user_id is not valid.'));
		}

		$documentId = self::readDocumentId($claims);
		if ($documentId === null)
		{
			return $result->addError(self::unauthorized('Claim document_id is not valid.'));
		}

		$requiredLevel = self::requiredLevelForOperation($operation);
		$claimedLevel = self::readAccessLevel($claims);
		if (!self::satisfies($claimedLevel, $requiredLevel))
		{
			return $result->addError(self::forbidden('Board access is not sufficient.'));
		}

		$document = self::parseDocumentId($documentId);
		if ($document !== null && $document['versionId'] !== null)
		{
			return $result->addError(self::forbidden('Comments are turned off in a historical version.'));
		}

		$file = $document === null ? null : $this->loadFile($document['objectId']);
		if ($file === null || $file->isDeleted())
		{
			return $result->addError(self::forbidden('Board is not available.'));
		}

		$isGuest = !self::isRegisteredUserId($userId);
		$sessionId = self::readSessionId($claims);
		$grantedLevel = $isGuest
			? $this->resolveGuestLevel($file, $sessionId)
			: $this->resolveUserLevel($file, (int)$userId, $sessionId);

		$effectiveLevel = self::lowerOf($claimedLevel, $grantedLevel);
		if ($isGuest)
		{
			$effectiveLevel = self::lowerOf($effectiveLevel, AccessContext::ACCESS_READ);
		}

		if (!self::satisfies($effectiveLevel, $requiredLevel))
		{
			return $result->addError(self::forbidden('Current rights on the board are not sufficient.'));
		}

		$result->setData([
			'context' => new AccessContext(
				$userId,
				$documentId,
				$effectiveLevel,
				$isGuest,
				(int)$file->getId(),
			),
		]);

		return $result;
	}

	private function extractBearerToken(HttpRequest $request): ?string
	{
		$authorization = $request->getHeader('Authorization');
		if (!is_string($authorization) || $authorization === '')
		{
			$authorization = $request->getServer()->get('REMOTE_USER');
		}

		if (!is_string($authorization) || !preg_match(self::BEARER_PATTERN, $authorization, $match))
		{
			return null;
		}

		return $match[1];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function decodeToken(string $token): ?array
	{
		if ($this->isUsingDocumentProxy())
		{
			return $this->decodeProxyToken($token);
		}

		try
		{
			$payload = JWT::decode($token, $this->getJwtSecret(), ['HS256']);
		}
		catch (\UnexpectedValueException|\InvalidArgumentException|\DomainException)
		{
			return null;
		}

		return is_object($payload) ? get_object_vars($payload) : null;
	}

	/**
	 * Direct Disk rights answer first; a registered reader of a link has none, so the active session
	 * of that user is then asked with the link access mixed in.
	 */
	private function resolveUserLevel(File $file, int $userId, ?string $sessionId): ?string
	{
		$securityContext = $this->createSecurityContext($file, $userId, false);
		$level = $securityContext === null
			? null
			: self::levelByRights($file->canRead($securityContext), $file->canUpdate($securityContext));

		if ($level === AccessContext::ACCESS_WRITE || $sessionId === null)
		{
			return $level;
		}

		$session = $this->findActiveSession($sessionId, (int)$file->getId(), $userId);
		if ($session === null)
		{
			return $level;
		}

		$linkSecurityContext = $this->createSecurityContext($file, $userId, true);
		if ($linkSecurityContext === null)
		{
			return $level;
		}

		return self::levelByRights(
			$session->canRead($linkSecurityContext),
			$session->canEdit($linkSecurityContext),
		);
	}

	/**
	 * A guest holds no Disk rights of their own: the active guest session and the external link it
	 * was opened through carry the right, so both have to be alive. Rows of a registered visitor may
	 * share the hash (a fork keeps it), so the lookup is pinned to the guest row. The level is capped
	 * to read by the caller: a guest never writes comments.
	 */
	private function resolveGuestLevel(File $file, ?string $sessionId): ?string
	{
		if ($sessionId === null)
		{
			return null;
		}

		$session = $this->findActiveSession($sessionId, (int)$file->getId(), GuestUser::GUEST_USER_ID);
		if ($session === null || !GuestUser::isGuestUserId($session->getUserId()))
		{
			return null;
		}

		$externalLink = $this->loadExternalLink($session);
		if ($externalLink === null || $externalLink->isExpired())
		{
			return null;
		}

		return $session->isEdit() && $externalLink->allowEdit()
			? AccessContext::ACCESS_WRITE
			: AccessContext::ACCESS_READ;
	}

	protected function isUsingDocumentProxy(): bool
	{
		return Configuration::isUsingDocumentProxy();
	}

	protected function getJwtSecret(): string
	{
		return Configuration::getJwtSecret();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	protected function decodeProxyToken(string $token): ?array
	{
		return (new JwtService())->decodeWebhookData($token);
	}

	protected function loadFile(int $objectId): ?File
	{
		$file = File::loadById($objectId);

		return $file instanceof File ? $file : null;
	}

	/**
	 * A version session is refused before the lookup, so only a session of the board itself matches.
	 */
	protected function findActiveSession(string $externalHash, int $objectId, int $userId): ?DocumentSession
	{
		return DocumentSession::load([
			'=EXTERNAL_HASH' => $externalHash,
			'=SERVICE' => DocumentService::FlipChart->value,
			'=STATUS' => DocumentSession::STATUS_ACTIVE,
			'=OBJECT_ID' => $objectId,
			'=USER_ID' => $userId,
			[
				'LOGIC' => 'OR',
				['=VERSION_ID' => null],
				['=VERSION_ID' => 0],
			],
		]);
	}

	protected function loadExternalLink(DocumentSession $session): ?ExternalLink
	{
		try
		{
			return $session->getContext()?->getExternalLink();
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	protected function createSecurityContext(File $file, int $userId, bool $withLinkAccess): ?SecurityContext
	{
		return $file->getStorage()?->getSecurityContext($userId, $withLinkAccess);
	}

	/**
	 * @param array<string, mixed> $claims
	 */
	private static function readUserId(array $claims): ?string
	{
		$value = $claims[self::CLAIM_USER_ID] ?? null;
		if (is_int($value))
		{
			return (string)$value;
		}

		return is_string($value) && $value !== '' ? $value : null;
	}

	/**
	 * @param array<string, mixed> $claims
	 */
	private static function readDocumentId(array $claims): ?string
	{
		$value = $claims[self::CLAIM_DOCUMENT_ID] ?? null;

		return is_string($value) && $value !== '' ? $value : null;
	}

	/**
	 * Absent, empty or unknown values mean no access; they never make the token itself invalid.
	 *
	 * @param array<string, mixed> $claims
	 */
	private static function readAccessLevel(array $claims): ?string
	{
		$value = $claims[self::CLAIM_ACCESS] ?? null;

		return in_array($value, [AccessContext::ACCESS_READ, AccessContext::ACCESS_WRITE], true) ? $value : null;
	}

	/**
	 * @param array<string, mixed> $claims
	 */
	private static function readSessionId(array $claims): ?string
	{
		$value = $claims[self::CLAIM_SESSION_ID] ?? null;

		return is_string($value) && $value !== '' ? $value : null;
	}

	/**
	 * @return array{objectId: int, versionId: int|null}|null
	 */
	private static function parseDocumentId(string $documentId): ?array
	{
		if (!preg_match(self::DOCUMENT_ID_PATTERN, $documentId, $match))
		{
			return null;
		}

		return [
			'objectId' => (int)$match[1],
			'versionId' => isset($match[2]) ? (int)$match[2] : null,
		];
	}

	private static function isRegisteredUserId(string $userId): bool
	{
		return preg_match(self::REGISTERED_USER_ID_PATTERN, $userId) === 1;
	}

	private static function requiredLevelForOperation(string $operation): string
	{
		return self::REQUIRED_LEVEL_BY_OPERATION[$operation] ?? throw new ArgumentOutOfRangeException('operation');
	}

	private static function levelByRights(bool $canRead, bool $canUpdate): ?string
	{
		if ($canUpdate)
		{
			return AccessContext::ACCESS_WRITE;
		}

		return $canRead ? AccessContext::ACCESS_READ : null;
	}

	private static function satisfies(?string $level, string $requiredLevel): bool
	{
		if ($level === null)
		{
			return false;
		}

		return $level === AccessContext::ACCESS_WRITE || $requiredLevel === AccessContext::ACCESS_READ;
	}

	private static function lowerOf(?string $first, ?string $second): ?string
	{
		if ($first === null || $second === null)
		{
			return null;
		}

		return $first === AccessContext::ACCESS_WRITE && $second === AccessContext::ACCESS_WRITE
			? AccessContext::ACCESS_WRITE
			: AccessContext::ACCESS_READ;
	}

	private static function unauthorized(string $message): Error
	{
		return new Error($message, self::ERROR_UNAUTHORIZED, ['status' => 401]);
	}

	private static function forbidden(string $message): Error
	{
		return new Error($message, self::ERROR_FORBIDDEN, ['status' => 403]);
	}
}
