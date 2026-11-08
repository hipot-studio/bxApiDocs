<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\Storage;

/**
 * Neutral guard context assembled by the reverse pipeline from the published template baseline. templateId
 * expands the per-template token inside a code before a data-view lookup; baselineStaticNodeFingerprints is the
 * set (fingerprint => true) of the static-code consumer nodes the published template already carries, against
 * which an incoming static consumer is allowed only as a byte-for-byte immutable passthrough;
 * baselineStorageCodes are the storage codes the published template declares (immutable create nodes plus the
 * token codes its own consumers use), which is what makes a token code editable at all - the token alone proves
 * nothing about ownership, since the runtime expands it inside an arbitrary prefix/suffix;
 * ownedStorageCodes are the codes the template itself creates - the create nodes of the incoming graph that the
 * admission policy let through, plus the create nodes of the published baseline.
 *
 * The two code sets stay separate on purpose. baselineStorageCodes answers "the published version mentioned
 * this code" and also collects the token codes of its consumers, so the origin of a code in it is unproven;
 * ownedStorageCodes answers "this template creates the storage", which is what authorises a consumer of any
 * code form. Merging them would let a template edit storage it has only ever read.
 *
 * Code lookup is normalised by {@see StorageReferenceGuard::normalizeCode()} - the same function the create-node
 * admission policy matches a code against the baseline by, so what this context calls one code and what the
 * policy calls one code cannot drift apart. It is case-insensitive on purpose: storage codes resolve
 * case-insensitively in the database, so a case-shifted code would otherwise address the very same storage
 * while escaping the baseline check. It stays case-insensitive after the fail-closed rule below, because that
 * rule already removes the codes whose expansion
 * the barrier cannot predict - the only ones a case-shifted match could have laundered. Edge whitespace is not
 * normalised away, in either direction: the CODE column compares NO PAD, so 'X ' and 'X' are two different rows,
 * and a padded code never reaches the comparison at all - the fail-closed rule refuses it first.
 */
final class StorageAuthorizationContext
{
	/** @var array<string, true> */
	private readonly array $declaredStorageCodes;
	/** @var array<string, true> */
	private readonly array $ownedStorageCodes;

	/**
	 * @param array<string, true> $baselineStaticNodeFingerprints
	 * @param list<string> $baselineStorageCodes storage codes declared by the published template, as written
	 * @param list<string> $ownedStorageCodes storage codes the template itself creates, as written
	 */
	public function __construct(
		public readonly int $templateId,
		public readonly array $baselineStaticNodeFingerprints,
		array $baselineStorageCodes = [],
		array $ownedStorageCodes = [],
	) {
		$this->declaredStorageCodes = self::indexCodes($baselineStorageCodes);
		$this->ownedStorageCodes = self::indexCodes($ownedStorageCodes);
	}

	public function declaresStorageCode(string $code): bool
	{
		return StorageReferenceGuard::isFullyInterpretableCode($code)
			&& isset($this->declaredStorageCodes[StorageReferenceGuard::normalizeCode($code)])
		;
	}

	public function ownsStorageCode(string $code): bool
	{
		return StorageReferenceGuard::isFullyInterpretableCode($code)
			&& isset($this->ownedStorageCodes[StorageReferenceGuard::normalizeCode($code)])
		;
	}

	/**
	 * Fail-closed on both sides of the comparison: a code carrying a substitution the guard cannot expand is
	 * neither indexed nor answered for. Both sets grant authority by TEXT, while the runtime grants access by
	 * what the text expands to - so a code whose expansion the barrier cannot predict must grant nothing. It is
	 * the byte-wise passthrough of a published node (rule 4) that keeps such codes round-tripping; it reads the
	 * node fingerprint and never these sets, so nothing a human published stops working here.
	 *
	 * @param list<string> $codes
	 * @return array<string, true>
	 */
	private static function indexCodes(array $codes): array
	{
		$indexed = [];
		foreach ($codes as $code)
		{
			$code = (string)$code;
			$normalized = StorageReferenceGuard::normalizeCode($code);
			if ($normalized !== '' && StorageReferenceGuard::isFullyInterpretableCode($code))
			{
				$indexed[$normalized] = true;
			}
		}

		return $indexed;
	}
}
