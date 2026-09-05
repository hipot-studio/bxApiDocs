<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink;

use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Security\Sign\Signer;

/**
 * The `_uls` parameter: proof that an id was handed out by a legitimate surface rather than guessed.
 * It grants nothing by itself, every consumer weighing it together with the unified link access check,
 * and it is bound neither in time nor to a reader — so its payload has to name exactly what it opens
 * and nothing besides.
 *
 * A file is named by its id alone, the payload the parameter has carried since it appeared. A revision
 * is named by the pair it belongs to, the two contexts kept apart by the separator: the signature of a
 * file opens no revision of it, and the signature of one revision opens neither its neighbour nor a
 * revision of another file.
 */
final class UnifiedLinkSignature
{
	private const VERSION_SEPARATOR = 'V';

	public function __construct(
		private readonly Signer $signer = new Signer(),
	)
	{
	}

	public function getUlsForObjectId(int $objectId): string
	{
		return $this->signer->getSignature((string)$objectId);
	}

	public function validateUlsForObjectId(int $objectId, string $uls): bool
	{
		return $this->validate((string)$objectId, $uls);
	}

	public function getUlsForVersion(int $objectId, int $versionId): string
	{
		return $this->signer->getSignature($this->buildVersionPayload($objectId, $versionId));
	}

	public function validateUlsForVersion(int $objectId, int $versionId, string $uls): bool
	{
		return $this->validate($this->buildVersionPayload($objectId, $versionId), $uls);
	}

	/**
	 * The value comes from the query, so anything at all may arrive in it: a signature that is not even
	 * hexadecimal is a refusal like any other mismatch, not a server error.
	 */
	private function validate(string $payload, string $uls): bool
	{
		try
		{
			return $this->signer->validate($payload, $uls);
		}
		catch (BadSignatureException)
		{
			return false;
		}
	}

	private function buildVersionPayload(int $objectId, int $versionId): string
	{
		return $objectId . self::VERSION_SEPARATOR . $versionId;
	}
}
