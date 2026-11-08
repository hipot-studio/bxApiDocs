<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\LegalRegistry;

use Bitrix\Crm\V2\Internal\Repository\LegalRegistry\LedgerRepository;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\SubmissionResolution;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\SubjectReference;
use Bitrix\Main\Security\Sign\Signer;

final class SubjectResolver
{
	private const PURPOSE = 'crm.legal-registry.submission-resolution.v1';

	public function resolve(SubmissionResolution $resolution, ?array $expectedSubmissionContext = null): SubjectReference
	{
		try
		{
			if (strlen($resolution->crmReceipt) > 65536)
			{
				throw new \UnexpectedValueException('SUBJECT_UNRESOLVED');
			}
			$verified = (new Signer())->unsign($resolution->crmReceipt, self::PURPOSE);
			$payload = json_decode(base64_decode($verified, true), true, 32, JSON_THROW_ON_ERROR);
			$expected = $this->payload($resolution->originalSubject, $resolution->personBindings,
				$resolution->resultId, $resolution->activityId,
				$expectedSubmissionContext ?? ($payload['submissionContext'] ?? []));
			if (!hash_equals(LedgerRepository::canonicalJson($expected), LedgerRepository::canonicalJson($payload)))
			{
				throw new \UnexpectedValueException('SUBJECT_UNRESOLVED');
			}
			$contacts = []; $leads = [];
			foreach ($resolution->personBindings as $binding)
			{
				if ($binding->entityTypeId === SubjectReference::CONTACT) { $contacts[] = $binding; }
				else { $leads[] = $binding; }
			}
			if (count($contacts) === 1)
			{
				return $contacts[0];
			}
			if ($contacts === [] && count($leads) === 1)
			{
				return $leads[0];
			}
		}
		catch (\Throwable) { throw new \UnexpectedValueException('SUBJECT_UNRESOLVED'); }
		throw new \UnexpectedValueException('SUBJECT_UNRESOLVED');
	}

	/** Only a trusted CRM PHP adapter may issue this receipt after verifying its outcome. */
	public function issueReceipt(SubjectReference $original, array $bindings, ?int $resultId, ?int $activityId,
		array $submissionContext = []): string
	{
		$payload = LedgerRepository::canonicalJson($this->payload($original, $bindings, $resultId, $activityId,
			$submissionContext));
		if (strlen($payload) > 32768)
		{
			throw new \InvalidArgumentException('Invalid resolution receipt.');
		}

		return (new Signer())->sign(base64_encode($payload), self::PURPOSE);
	}

	private function payload(SubjectReference $original, array $bindings, ?int $resultId, ?int $activityId,
		array $context): array
	{
		// The DTO validates references, outcome IDs and uniqueness; its receipt is not authority.
		$validated = new SubmissionResolution($original, $bindings, $resultId, $activityId, 'internal-validation');
		$refs = array_map(static fn(SubjectReference $ref): array => ['entityTypeId' => $ref->entityTypeId,
			'entityId' => $ref->entityId], $validated->personBindings);
		usort(
			$refs,
			static fn(array $a, array $b): int => [$a['entityTypeId'], $a['entityId']] <=> [$b['entityTypeId'], $b['entityId']],
		);

		return ['original' => ['entityTypeId' => $original->entityTypeId, 'entityId' => $original->entityId],
			'bindings' => $refs,
			'resultId' => $resultId, 'activityId' => $activityId, 'submissionContext' => $context];
	}
}
