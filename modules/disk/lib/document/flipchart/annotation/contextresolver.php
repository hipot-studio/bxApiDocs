<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Annotation;

use Bitrix\Disk\Document\Flipchart\Configuration;
use Bitrix\Disk\Document\Flipchart\JwtService;
use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\GuestUser;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Disk\Version;
use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Result;
use Bitrix\Main\Web\JWT;

/**
 * Turns the Bearer JWT of an annotations request into an {@see AccessContext}.
 *
 * The order of refusals is the one of the contract: token and required claims (401), then the
 * `annotations_access` claim and the current rights on a resolved board (403). A board that cannot
 * be resolved is not refused here: the context is returned unresolved so that the syntax of the
 * request is checked before the missing document is reported.
 *
 * The protected methods are the seams to the portal: token verification, file, version, session,
 * external link and security context. Tests stand them in; production keeps the defaults.
 */
class ContextResolver
{
	private const CLAIM_USER_ID = 'user_id';
	private const CLAIM_DOCUMENT_ID = 'document_id';
	private const CLAIM_ACCESS = 'annotations_access';
	private const CLAIM_SESSION_ID = 'session_id';

	private const ERROR_UNAUTHORIZED = 'unauthorized';
	private const ERROR_FORBIDDEN = 'forbidden';

	private const BEARER_PATTERN = '/^Bearer ([A-Za-z0-9\-._~+\/]+=*)$/D';
	private const REGISTERED_USER_ID_PATTERN = '/^[1-9][0-9]{0,18}$/D';
	private const DOCUMENT_ID_PATTERN = '/^(?:.*-)?([1-9][0-9]{0,9})(?:\.([1-9][0-9]{0,9}))?$/D';

	/**
	 * @return Result On success `getData()['context']` holds the {@see AccessContext}.
	 */
	public function resolve(HttpRequest $request): Result
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

		$requiredLevel = self::requiredLevelForMethod((string)$request->getRequestMethod());
		$claimedLevel = self::readAccessLevel($claims);
		if (!self::satisfies($claimedLevel, $requiredLevel))
		{
			return $result->addError(self::forbidden('Annotations access is not sufficient.'));
		}

		$isGuest = !self::isRegisteredUserId($userId);
		$document = $this->resolveDocument($documentId);
		if ($document === null)
		{
			$result->setData(['context' => new AccessContext($userId, $documentId, $claimedLevel, $isGuest)]);

			return $result;
		}

		[$file, $version] = $document;
		$sessionId = self::readSessionId($claims);
		$grantedLevel = $isGuest
			? $this->resolveGuestLevel($file, $version, $sessionId)
			: $this->resolveUserLevel($file, $version, (int)$userId, $sessionId);

		$effectiveLevel = self::lowerOf($claimedLevel, $grantedLevel);
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
				$version === null ? null : (int)$version->getId(),
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
	 * @return array{0: File, 1: ?Version}|null
	 */
	private function resolveDocument(string $documentId): ?array
	{
		if (!preg_match(self::DOCUMENT_ID_PATTERN, $documentId, $match))
		{
			return null;
		}

		$file = $this->loadFile((int)$match[1]);
		if ($file === null || $file->isDeleted())
		{
			return null;
		}

		$version = null;
		if (isset($match[2]))
		{
			$version = $this->loadVersion($file, (int)$match[2]);
			if ($version === null)
			{
				return null;
			}
		}

		return [$file, $version];
	}

	/**
	 * Direct Disk rights answer first; a registered reader of a link has none, so the active session
	 * of that user is then asked with the link access mixed in.
	 */
	private function resolveUserLevel(File $file, ?Version $version, int $userId, ?string $sessionId): ?string
	{
		$securityContext = $this->createSecurityContext($file, $userId, false);
		$level = $securityContext === null
			? null
			: self::levelByRights($file->canRead($securityContext), $file->canUpdate($securityContext));

		if ($level === AccessContext::ACCESS_WRITE || $sessionId === null)
		{
			return $level;
		}

		$session = $this->findActiveSession(
			$sessionId,
			(int)$file->getId(),
			$version === null ? null : (int)$version->getId(),
			$userId,
		);
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
	 * share the hash (a fork keeps it), so the lookup is pinned to the guest row.
	 */
	private function resolveGuestLevel(File $file, ?Version $version, ?string $sessionId): ?string
	{
		if ($sessionId === null)
		{
			return null;
		}

		$session = $this->findActiveSession(
			$sessionId,
			(int)$file->getId(),
			$version === null ? null : (int)$version->getId(),
			GuestUser::GUEST_USER_ID,
		);
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

	protected function loadVersion(File $file, int $versionId): ?Version
	{
		$version = $file->getVersion($versionId);

		return $version instanceof Version ? $version : null;
	}

	protected function findActiveSession(
		string $externalHash,
		int $objectId,
		?int $versionId,
		?int $userId,
	): ?DocumentSession
	{
		$filter = [
			'=EXTERNAL_HASH' => $externalHash,
			'=SERVICE' => DocumentService::FlipChart->value,
			'=STATUS' => DocumentSession::STATUS_ACTIVE,
			'=OBJECT_ID' => $objectId,
		];
		if ($versionId === null)
		{
			$filter[] = [
				'LOGIC' => 'OR',
				['=VERSION_ID' => null],
				['=VERSION_ID' => 0],
			];
		}
		else
		{
			$filter['=VERSION_ID'] = $versionId;
		}
		if ($userId !== null)
		{
			$filter['=USER_ID'] = $userId;
		}

		return DocumentSession::load($filter);
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

	private static function isRegisteredUserId(string $userId): bool
	{
		return preg_match(self::REGISTERED_USER_ID_PATTERN, $userId) === 1;
	}

	private static function requiredLevelForMethod(string $method): string
	{
		return in_array(strtoupper($method), ['GET', 'HEAD'], true)
			? AccessContext::ACCESS_READ
			: AccessContext::ACCESS_WRITE;
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
