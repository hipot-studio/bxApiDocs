<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\LegalRegistry;

use Bitrix\Crm\ContactTable;
use Bitrix\Crm\LeadTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\SubmissionTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\SourceTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\FormSubmissionReceiptTable;
use Bitrix\Crm\V2\Internal\Repository\LegalRegistry\FormSubmissionReceiptRepository;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\ManifestItem;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\SubmissionManifest;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\SubmissionResolution;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\SubjectReference;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\TrustedSubmissionContext;
use Bitrix\Main\Application;

final class FormSubmissionRecovery
{
	/** Reads frozen server authority; a caller supplied RegistryResult is never authority. */
	public function frozen(int $submissionId, string $requestHash, ?int $formId = null): array
	{
		if ($submissionId <= 0 || preg_match('/^[a-f0-9]{64}$/D', $requestHash) !== 1)
		{
			throw new \InvalidArgumentException('INVALID_PAYLOAD');
		}
		$row = $this->readSubmission($submissionId);
		if ($row === null) { throw new \UnexpectedValueException('NOT_FOUND_OR_DENIED'); }
		$frozen = json_decode($row['MANIFEST'], true, 512, JSON_THROW_ON_ERROR);
		$storedHash = $frozen['formSubmission']['requestHash'] ?? null;
		if (!is_string($storedHash) || !hash_equals($storedHash, $requestHash))
		{
			throw new \UnexpectedValueException('IDEMPOTENCY_CONFLICT');
		}
		if ($formId !== null && ($frozen['formId'] ?? null) !== $formId)
		{
			throw new \UnexpectedValueException('NOT_FOUND_OR_DENIED');
		}
		$items = array_map(static fn(array $item): ManifestItem => new ManifestItem(
			$item['documentId'], $item['versionId'], $item['bindingRevision'], $item['effectiveText'],
			$item['effectiveLabel'], $item['substitutions'], $item['textHash'], $item['answer'],
		), $frozen['items']);
		$key = base64_decode($frozen['submissionKeyBase64'], true);
		if ($key === false || !hash_equals($row['SUBMISSION_KEY'], $key))
		{
			throw new \UnexpectedValueException('INVALID_PAYLOAD');
		}
		$manifest = new SubmissionManifest(
			$key, $frozen['formId'], $frozen['sourceRevision'], $items, new \DateTimeImmutable($frozen['presentedAt']),
		);
		$context = new TrustedSubmissionContext($manifest->formId, $frozen['formSubmission']['trustedToken']);
		$proof = (new PresentationValidator())->verify($context, $manifest);
		if ($proof['sourceId'] !== (int)$row['SOURCE_ID']
			|| !hash_equals($row['PAYLOAD_HASH'], PresentationValidator::identityHash($manifest)))
		{
			throw new \UnexpectedValueException('INVALID_PAYLOAD');
		}

		return ['row' => $row, 'manifest' => $manifest, 'context' => $context];
	}

	public function complete(int $submissionId, string $requestHash): RegistryResult
	{
		$availability = FeatureGate::requireEnabled();
		if (!$availability->isSuccess()) { return $availability; }
		$policy = PolicyGate::getInstance()->requireCollection();
		if (!$policy->isSuccess()) { return $policy; }
		$verified = false;
		try
		{
			$frozen = $this->frozen($submissionId, $requestHash);
			$verified = true;
			$receipt = (new FormSubmissionReceiptRepository())->find($submissionId, $requestHash);
			if ($receipt === null) { return SubmissionService::error('INVALID_STATE')->setSubmissionId($submissionId)
				->setState($frozen['row']['STATE']); }
			$resolution = $this->resolution($frozen, $receipt);
			$result = SubmissionService::getInstance()->execute(
				$frozen['context'], 'complete', $frozen['manifest'], $resolution,
			);
			if (!$result->isSuccess()) { $this->rememberPending($submissionId, $result->getErrors()[0]->getCode()); }

			return $result;
		}
		catch (\UnexpectedValueException $error)
		{
			$code = in_array($error->getMessage(), [
				'SUBJECT_UNRESOLVED', 'IDEMPOTENCY_CONFLICT', 'NOT_FOUND_OR_DENIED',
			], true) ? $error->getMessage() : 'INVALID_PAYLOAD';
			if ($verified) { $this->rememberPending($submissionId, $code); }
			return SubmissionService::error($code)->setSubmissionId($submissionId)->setState('pending');
		}
		catch (\Throwable)
		{
			if ($verified) { $this->rememberPending($submissionId, 'STORAGE_ERROR'); }
			return SubmissionService::error('STORAGE_ERROR')->setSubmissionId($submissionId)->setState('pending');
		}
	}

	public function recover(int $limit = 100, int $cursor = 0): RegistryResult
	{
		$availability = FeatureGate::requireEnabled();
		if (!$availability->isSuccess()) { return $availability; }
		$policy = PolicyGate::getInstance()->requireCollection();
		if (!$policy->isSuccess()) { return $policy; }
		if ($limit < 1 || $limit > 100 || $cursor < 0 || $cursor > 2147483647)
		{
			return SubmissionService::error('INVALID_PAYLOAD');
		}
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$rows = SubmissionTable::query()->setSelect(['ID', 'MANIFEST'])->where('STATE', 'pending')
				->where('ID', '>', $cursor)->setOrder(['ID' => 'ASC'])->setLimit($limit)->fetchAll();
		}
		finally { $pool->useMasterOnly(false); }
		$committed = 0; $pending = 0;
		foreach ($rows as $row)
		{
			$cursor = (int)$row['ID'];
			$frozen = json_decode($row['MANIFEST'], true, 512, JSON_THROW_ON_ERROR);
			$hash = $frozen['formSubmission']['requestHash'] ?? null;
			if (!is_string($hash)) { continue; }
			$result = $this->complete($cursor, $hash);
			if ($result->isSuccess() && $result->getState() === 'committed') { $committed++; }
			else { $pending++; }
		}

		return (new RegistryResult())->setTotalCount(count($rows))->setNextCursor(count($rows) === $limit ? $cursor : null)
			->setState($pending > 0 ? 'pending' : 'committed');
	}

	public function markFailed(int $submissionId, string $requestHash): RegistryResult
	{
		return SubmissionService::transaction(function () use ($submissionId, $requestHash): RegistryResult {
			$frozen = $this->frozen($submissionId, $requestHash);
			SubmissionService::currentRows(SourceTable::class, ['=ID' => (int)$frozen['row']['SOURCE_ID']]);
			SubmissionService::currentRows(SubmissionTable::class, ['=ID' => $submissionId]);
			$receipt = SubmissionService::currentRows(FormSubmissionReceiptTable::class,
				['=SUBMISSION_ID' => $submissionId])[0] ?? null;
			if ($receipt !== null && $receipt['STATE'] === 'committed')
			{
				return SubmissionService::error('INVALID_STATE');
			}

			return SubmissionService::getInstance()->execute($frozen['context'], 'markFailed', $frozen['manifest']);
		});
	}

	private function resolution(array $frozen, array $receipt): SubmissionResolution
	{
		$manifest = $frozen['manifest'];
		$row = $frozen['row'];
		$resultId = $receipt['resultId'];
		if (!is_int($resultId) || $resultId <= 0 || ($receipt['outcome']['formId'] ?? null) !== $manifest->formId)
		{
			throw new \UnexpectedValueException('INVALID_CRM_OUTCOME');
		}
		$bindings = []; $identities = [];
		foreach ($receipt['outcome']['entities'] ?? [] as $entity)
		{
			$type = \CCrmOwnerType::ResolveID($entity['ENTITY_TYPE']);
			$id = $entity['ENTITY_ID'];
			if (!in_array($type, [SubjectReference::LEAD, SubjectReference::CONTACT], true)) { continue; }
			if (!is_int($id) || $id <= 0)
			{
				throw new \UnexpectedValueException('INVALID_CRM_OUTCOME');
			}
			if (isset($identities[$type . ':' . $id])) { continue; }
			$table = $type === SubjectReference::CONTACT ? ContactTable::class : LeadTable::class;
			if ($row['STATE'] !== 'committed' && !$table::getById($id)->fetch())
			{
				throw new \UnexpectedValueException('SUBJECT_UNRESOLVED');
			}
			$identities[$type . ':' . $id] = true;
			$bindings[] = new SubjectReference($type, $id);
		}
		$contacts = array_values(array_filter($bindings, static fn(SubjectReference $ref): bool =>
			$ref->entityTypeId === SubjectReference::CONTACT));
		$leads = array_values(array_filter($bindings, static fn(SubjectReference $ref): bool =>
			$ref->entityTypeId === SubjectReference::LEAD));
		$original = count($contacts) === 1 ? $contacts[0] : ($contacts === [] && count($leads) === 1 ? $leads[0] : null);
		if ($original === null) { throw new \UnexpectedValueException('SUBJECT_UNRESOLVED'); }
		$activityId = $receipt['outcome']['activityId'] ?? null;
		$context = [
			'sourceId' => (int)$row['SOURCE_ID'], 'submissionId' => (int)$row['ID'],
			'submissionKeyBase64' => base64_encode($row['SUBMISSION_KEY']), 'payloadHash' => $row['PAYLOAD_HASH'],
		];
		$resolver = new SubjectResolver();
		$resolution = new SubmissionResolution($original, $bindings, $resultId, $activityId,
			$resolver->issueReceipt($original, $bindings, $resultId, $activityId, $context));
		$resolver->resolve($resolution, $context);

		return $resolution;
	}

	private function readSubmission(int $submissionId): ?array
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try { return SubmissionTable::getById($submissionId)->fetch() ?: null; }
		finally { $pool->useMasterOnly(false); }
	}

	private function rememberPending(int $submissionId, string $code): void
	{
		SubmissionService::transaction(static function () use ($submissionId, $code): RegistryResult {
			$row = SubmissionService::currentRows(SubmissionTable::class, ['=ID' => $submissionId])[0] ?? null;
			if ($row !== null && $row['STATE'] === 'pending')
			{
				$saved = SubmissionTable::update($submissionId, ['ERROR_CODE' => $code]);
				if (!$saved->isSuccess()) { return SubmissionService::error('STORAGE_ERROR'); }
			}

			return new RegistryResult();
		});
	}
}
