<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\LegalRegistry;

use Bitrix\Crm\V2\Internal\Repository\LegalRegistry\LedgerRepository;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\SourceTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\SubmissionTable;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\TrustedSubmissionContext;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\ManifestItem;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\SubmissionManifest;
use DateTimeImmutable;

final class FormSubmissionService
{
	/** Hashes all already normalized server inputs before Fill can mutate CRM data. */
	public function hashNormalizedRequest(array $request): string
	{
		$encoded = json_encode($request, JSON_THROW_ON_ERROR);
		if (strlen($encoded) > 1048576) { throw new \InvalidArgumentException('INVALID_PAYLOAD'); }
		$this->validateRequest($request);

		return hash('sha256', LedgerRepository::canonicalJson($request));
	}

	/** Null means a valid new attempt; every invalid or existing attempt returns a controlled result. */
	public function replay(
		int $formId,
		string $presentationToken,
		string $attemptKey,
		array $answers,
		string $normalizedRequestHash,
	): ?RegistryResult
	{
		$probe = SubmissionService::transaction(function () use (
			$formId, $presentationToken, $attemptKey, $answers, $normalizedRequestHash,
		): RegistryResult {
			$this->validateInputs($formId, $attemptKey, $answers, $normalizedRequestHash);
			$payload = (new FormPresentationService())->readSigned($formId, $presentationToken);
			$manifest = $this->manifest($formId, $attemptKey, $answers, $payload);
			$source = SubmissionService::currentRows(SourceTable::class, ['=FORM_ID' => $formId])[0] ?? null;
			$row = $source === null ? null : SubmissionService::currentRows(SubmissionTable::class, [
				'=SOURCE_ID' => (int)$source['ID'], '=SUBMISSION_KEY' => $attemptKey,
			])[0] ?? null;
			if ($row === null) { return new RegistryResult(); }
			$frozen = json_decode($row['MANIFEST'], true, 512, JSON_THROW_ON_ERROR);
			$hash = $frozen['formSubmission']['requestHash'] ?? null;
			if (!is_string($hash) || !hash_equals($hash, $normalizedRequestHash)
				|| !hash_equals($row['PAYLOAD_HASH'], PresentationValidator::identityHash($manifest)))
			{
				return SubmissionService::error('IDEMPOTENCY_CONFLICT');
			}
			$context = new TrustedSubmissionContext($formId, $frozen['formSubmission']['trustedToken'] ?? '');
			$proof = (new PresentationValidator())->verify($context, $manifest);
			if ($proof['sourceId'] !== $payload['sourceId'] || (int)$source['ID'] !== $payload['sourceId'])
			{
				return SubmissionService::error('IDEMPOTENCY_CONFLICT');
			}

			return (new RegistryResult())->setSubmissionId((int)$row['ID'])
				->setState($row['STATE'])->setReplay(true);
		});

		return !$probe->isSuccess() || $probe->wasReplay() ? $probe : null;
	}

	public function prepare(
		int $formId,
		string $presentationToken,
		string $attemptKey,
		array $answers,
		string $normalizedRequestHash,
	): RegistryResult
	{
		$policy = PolicyGate::getInstance()->requireCollection();
		if (!$policy->isSuccess()) { return $policy; }
		$replay = $this->replay($formId, $presentationToken, $attemptKey, $answers, $normalizedRequestHash);
		if ($replay !== null) { return $replay; }
		$manifest = null;
		$trusted = null;
		$current = SubmissionService::transaction(function () use (
			$formId, $presentationToken, $attemptKey, $answers, &$manifest, &$trusted,
		): RegistryResult {
			$snapshot = (new FormPresentationService())->verify($formId, $presentationToken);
			$manifest = $this->manifest($formId, $attemptKey, $answers, $snapshot['payload']);
			$trusted = (new PresentationValidator())->issue(
				$manifest, $snapshot['source'], $snapshot['bindings'], $snapshot['versions'],
			);

			return new RegistryResult();
		});
		if (!$current->isSuccess()) { return $current; }
		$prepared = SubmissionService::getInstance()->execute(
			$trusted, 'prepare', $manifest, null, $normalizedRequestHash, $presentationToken,
		);
		if (!$prepared->isSuccess()) { return $prepared; }

		// Anonymous callers receive no subject bindings, fact DTO or trusted server token.
		return (new RegistryResult())->setSubmissionId($prepared->getSubmissionId())
			->setState($prepared->getState())->setReplay($prepared->wasReplay());
	}

	private function validateInputs(int $formId, string $attemptKey, array $answers, string $hash): void
	{
		if ($formId <= 0 || $formId > 2147483647 || trim($attemptKey) === '' || strlen($attemptKey) > 255
			|| preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1 || !array_is_list($answers) || count($answers) > 100)
		{
			throw new \InvalidArgumentException('INVALID_PAYLOAD');
		}
	}

	private function manifest(int $formId, string $attemptKey, array $answers, array $payload): SubmissionManifest
	{
		$byDocument = [];
		foreach ($answers as $answer)
		{
			if (!is_array($answer) || count($answer) !== 3
				|| array_diff(['documentId', 'versionId', 'accepted'], array_keys($answer)) !== []
				|| !is_int($answer['documentId']) || !is_int($answer['versionId'])
				|| !is_bool($answer['accepted']) || isset($byDocument[$answer['documentId']]))
			{
				throw new \InvalidArgumentException('INVALID_PAYLOAD');
			}
			$byDocument[$answer['documentId']] = $answer;
		}
		$items = [];
		foreach ($payload['items'] as $item)
		{
			$answer = $byDocument[$item['documentId']] ?? null;
			if (($answer !== null && $answer['versionId'] !== $item['versionId'])
				|| ($item['required'] && !($answer['accepted'] ?? false)))
			{
				throw new \InvalidArgumentException('INVALID_PAYLOAD');
			}
			$items[] = new ManifestItem(
				$item['documentId'], $item['versionId'], $item['bindingRevision'],
				$item['text'], $item['label'], $item['substitutions'], $item['textHash'],
				($answer['accepted'] ?? false) ? 'accepted' : 'unmarked',
			);
			unset($byDocument[$item['documentId']]);
		}
		if ($byDocument !== []) { throw new \InvalidArgumentException('INVALID_PAYLOAD'); }

		return new SubmissionManifest(
			$attemptKey, $formId, $payload['sourceRevision'], $items, new DateTimeImmutable($payload['presentedAt']),
		);
	}

	public function committedOutcome(int $formId, int $submissionId, string $requestHash): ?array
	{
		$frozen = (new FormSubmissionRecovery())->frozen($submissionId, $requestHash, $formId);
		$receipt = (new \Bitrix\Crm\V2\Internal\Repository\LegalRegistry\FormSubmissionReceiptRepository())
			->find($submissionId, $requestHash);
		if ($receipt === null) { return null; }
		if (($receipt['outcome']['formId'] ?? null) !== $frozen['manifest']->formId
			|| !is_int($receipt['resultId']) || $receipt['resultId'] <= 0)
		{
			throw new \UnexpectedValueException('INVALID_PAYLOAD');
		}

		return $receipt;
	}

	/** Prepare is already durable; this transaction owns only the CRM writes and receipt. */
	public function commitResult(int $formId, int $submissionId, string $requestHash, callable $write): array
	{
		$this->requireRuntime();
		$connection = \Bitrix\Main\Application::getConnection();
		$level = new \ReflectionProperty($connection, 'transactionLevel');
		if ($level->getValue($connection) !== 0) { throw new \UnexpectedValueException('INVALID_STATE'); }
		(new FormSubmissionRecovery())->frozen($submissionId, $requestHash, $formId);

		return (new \Bitrix\Crm\V2\Internal\Repository\LegalRegistry\FormSubmissionReceiptRepository())->commit(
			$submissionId, $requestHash, function () use (
				$formId, $submissionId, $requestHash, $write, $connection, $level,
			): array {
				$frozen = (new FormSubmissionRecovery())->frozen($submissionId, $requestHash, $formId);
				if ($frozen['row']['STATE'] !== 'pending') { throw new \UnexpectedValueException('INVALID_STATE'); }
				$data = $write();
				$resultId = $data['resultId'] ?? null;
				$result = is_int($resultId) ? \Bitrix\Crm\WebForm\Internals\ResultTable::getById($resultId)->fetch() : false;
				if (!$result || (int)$result['FORM_ID'] !== $formId || !is_array($data['outcome'] ?? null))
				{
					throw new \UnexpectedValueException('INVALID_PAYLOAD');
				}
				foreach ($data['outcome']['entities'] ?? [] as $entity)
				{
					$type = \CCrmOwnerType::ResolveID($entity['ENTITY_TYPE']);
					$id = $entity['ENTITY_ID'];
					if ($type === \CCrmOwnerType::Activity) { continue; }
					$binding = \Bitrix\Crm\WebForm\Internals\ResultEntityTable::query()->setSelect(['ITEM_ID'])
						->where('FORM_ID', $formId)->where('RESULT_ID', $resultId)
						->where('ENTITY_NAME', $entity['ENTITY_TYPE'])->where('ITEM_ID', $id)->fetch();
					if (!$binding) { throw new \UnexpectedValueException('INVALID_PAYLOAD'); }
				}
				$activityId = $data['outcome']['activityId'] ?? null;
				if ($activityId !== null)
				{
					$activity = is_int($activityId) ? \Bitrix\Crm\ActivityTable::getById($activityId)->fetch() : false;
					if (!$activity || (int)$activity['ASSOCIATED_ENTITY_ID'] !== $resultId)
					{
						throw new \UnexpectedValueException('INVALID_PAYLOAD');
					}
				}
				$data['outcome']['formId'] = $formId;
				if ($level->getValue($connection) !== 1) { throw new \UnexpectedValueException('INVALID_STATE'); }

				return $data;
			},
		);
	}

	public function recordCrmFailure(int $formId, int $submissionId, string $requestHash): void
	{
		try
		{
			$this->requireRuntime();
			$frozen = (new FormSubmissionRecovery())->frozen($submissionId, $requestHash, $formId);
			if ($frozen['row']['STATE'] !== 'pending') { return; }
			$repository = new \Bitrix\Crm\V2\Internal\Repository\LegalRegistry\FormSubmissionReceiptRepository();
			if ($repository->find($submissionId, $requestHash) === null)
			{
				$repository->recordFailure($submissionId, $requestHash, 'STORAGE_ERROR', null);
			}
		}
		catch (\Throwable) { /* The durable prepare remains discoverable even when diagnostics storage fails. */ }
	}

	private function requireRuntime(): void
	{
		foreach ([FeatureGate::requireEnabled(), PolicyGate::getInstance()->requireCollection()] as $availability)
		{
			if (!$availability->isSuccess())
			{
				throw new \UnexpectedValueException($availability->getErrors()[0]->getCode());
			}
		}
	}

	private function validateRequest(array $request): void
	{
		foreach ($request as $value)
		{
			if (is_array($value)) { $this->validateRequest($value); }
			elseif (!is_scalar($value) && $value !== null)
			{
				throw new \InvalidArgumentException('INVALID_PAYLOAD');
			}
		}
	}
}
