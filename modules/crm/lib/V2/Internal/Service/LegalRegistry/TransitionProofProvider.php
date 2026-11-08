<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\LegalRegistry;

use Bitrix\Crm\V2\Internal\Repository\LegalRegistry\LedgerRepository;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\SubjectReference;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\SubjectTransition;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Security\Sign\Signer;

final class TransitionProofProvider
{
	private const PURPOSE = 'crm.legal-registry.confirmed-transition.v1';
	private array $proofs = [];

	public static function getInstance(): self
	{
		$locator = ServiceLocator::getInstance();
		if ($locator->has(self::class))
		{
			$service = $locator->get(self::class);
			if ($service instanceof self)
			{
				return $service;
			}
		}

		return new self();
	}

	public function confirms(SubjectTransition $transition): bool
	{
		try
		{
			$proof = $this->proofs[$transition->operationKey] ?? null;
			if (!is_string($proof) || strlen($proof) > 65536)
			{
				return false;
			}
			$verified = (new Signer())->unsign($proof, self::PURPOSE);

			return hash_equals(base64_encode(self::identity($transition)), $verified);
		}
		catch (\Throwable) { return false; }
	}

	/** Trusted CRM PHP adapters register proof only after their conversion/merge succeeds. */
	public function recordConfirmedTransition(SubjectTransition $transition): void
	{
		$this->registerProof($transition->operationKey, $this->issueProof($transition));
	}

	/** Internal server-side proof issuance; never a request receipt or caller approval flag. */
	public function issueProof(SubjectTransition $transition): string
	{
		$payload = self::identity($transition);
		if (strlen($payload) > 32768)
		{
			throw new \InvalidArgumentException('Invalid transition proof.');
		}

		return (new Signer())->sign(base64_encode($payload), self::PURPOSE);
	}

	public function registerProof(string $operationKey, string $proof): void
	{
		if (strlen($operationKey) > 244 || strlen($proof) > 65536)
		{
			throw new \InvalidArgumentException('Invalid transition proof.');
		}
		$this->proofs[$operationKey] = $proof;
	}

	public static function canonicalTransition(SubjectTransition $transition): SubjectTransition
	{
		$sort = static function (array $refs): array {
			usort(
				$refs,
				static fn(SubjectReference $a, SubjectReference $b): int => [$a->entityTypeId, $a->entityId] <=> [$b->entityTypeId, $b->entityId],
			);

			return $refs;
		};

		return new SubjectTransition(
			$transition->operationKey, $transition->kind, $sort($transition->from), $sort($transition->to),
		);
	}

	private static function identity(SubjectTransition $transition): string
	{
		if (strlen($transition->operationKey) > 244)
		{
			throw new \InvalidArgumentException('Invalid transition proof.');
		}
		$t = self::canonicalTransition($transition);

		return LedgerRepository::canonicalJson([
			'operationKeyBase64' => base64_encode($t->operationKey),
			'kind' => $t->kind, 'from' => $t->from, 'to' => $t->to,
		]);
	}
}
