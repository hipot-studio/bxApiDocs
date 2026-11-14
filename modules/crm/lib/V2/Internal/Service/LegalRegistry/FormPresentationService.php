<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\LegalRegistry;

use Bitrix\Crm\V2\Internal\Model\LegalRegistry\SourceTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\UsageTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\VersionTable;
use Bitrix\Crm\V2\Internal\Repository\LegalRegistry\CatalogRepository;
use Bitrix\Crm\V2\Internal\Repository\LegalRegistry\LedgerRepository;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\FormPresentation;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\ManifestItem;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\SubmissionManifest;
use Bitrix\Crm\WebForm\Form;
use Bitrix\Crm\WebForm\Internals\FieldTable;
use Bitrix\Crm\WebForm\Internals\FormTable;
use Bitrix\Main\Security\Sign\Signer;
use DateTimeImmutable;
use DateTimeZone;

final class FormPresentationService
{
	private const PURPOSE = 'crm.legal-registry.public-form-display.v1';
	private const MAX_TOKEN_BYTES = 2097152;
	private const MAX_PAYLOAD_BYTES = 1048576;
	// The freeze adds a trusted server token (at most 64 KiB), version rules and form metadata.
	private const MANIFEST_FREEZE_RESERVE_BYTES = 98304;

	public function issueForPublicForm(int $formId, array $context): RegistryResult
	{
		if (($context['formId'] ?? null) !== $formId || !is_string($context['securityCode'] ?? null)
			|| $context['securityCode'] === '')
		{
			return SubmissionService::error('NOT_FOUND_OR_DENIED');
		}

		return SubmissionService::transaction(function () use ($formId, $context): RegistryResult {
			$snapshot = $this->capture($formId, hash('sha256', $context['securityCode']), false);
			$presentedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
			$payload = [
				'formId' => $formId, 'sourceId' => (int)$snapshot['source']['ID'],
				'sourceRevision' => (int)$snapshot['source']['REVISION'],
				'securityHash' => hash('sha256', $context['securityCode']), 'items' => $snapshot['items'],
				'presentedAt' => $presentedAt->format('Y-m-d\TH:i:s.uP'), 'nonce' => bin2hex(random_bytes(16)),
			];
			$json = LedgerRepository::canonicalJson($payload);
			if (strlen($json) > self::MAX_PAYLOAD_BYTES) { return SubmissionService::error('INVALID_PAYLOAD'); }
			$token = (new Signer())->sign(base64_encode($json), self::PURPOSE);
			$presentation = new FormPresentation(
				$token, $payload['sourceRevision'], self::publicItems($snapshot['items']), $presentedAt,
			);

			return (new RegistryResult())->setPresentation($presentation);
		});
	}

	/** Checks the proposed projection while the editor still holds the form mutation lock. */
	public function fitsBindings(int $formId, array $bindings): bool
	{
		if (count($bindings) > 100) { return false; }
		$fieldRows = FieldTable::getList([
			'select' => ['CAPTION'], 'filter' => ['=FORM_ID' => $formId], 'order' => ['SORT' => 'ASC', 'CAPTION' => 'ASC'],
		])->fetchAll();
		$fields = array_map(static fn(array $row): string => (string)$row['CAPTION'], $fieldRows);
		$versionRows = VersionTable::getList(['filter' => [
			'@ID' => array_values(array_unique(array_column($bindings, 'versionId'))),
		]])->fetchAll();
		$versions = array_column($versionRows, null, 'ID');
		$items = [];
		foreach ($bindings as $binding)
		{
			$versionRow = $versions[$binding['versionId']] ?? null;
			if ($versionRow === null || (int)$versionRow['DOCUMENT_ID'] !== $binding['documentId'])
			{
				return false;
			}
			$version = CatalogRepository::mapVersion($versionRow);
			$substitutions = [];
			foreach ($version->substitutionSchema as $key => $type)
			{
				if (mb_strtolower($key) !== 'fields' || $type !== 'string[]') { return false; }
				$substitutions[$key] = $fields;
			}
			$rendered = (new VersionRenderer())->render($version, $substitutions);
			$items[] = [
				'documentId' => $binding['documentId'], 'versionId' => $binding['versionId'],
				'bindingRevision' => 2147483647, 'label' => $rendered['label'], 'text' => $rendered['text'],
				'textHash' => $rendered['textHash'], 'checked' => $binding['checked'],
				'required' => $binding['required'], 'notice' => $binding['notice'], 'order' => $binding['order'],
				'substitutions' => $substitutions, 'contentHash' => $version->contentHash,
			];
		}
		$presentedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$payload = [
			'formId' => $formId, 'sourceId' => 2147483647,
			'sourceRevision' => 2147483647,
			'securityHash' => str_repeat('0', 64), 'items' => $items,
			'presentedAt' => $presentedAt->format('Y-m-d\TH:i:s.uP'), 'nonce' => str_repeat('0', 32),
		];
		$payloadJson = LedgerRepository::canonicalJson($payload);
		if (strlen($payloadJson) > self::MAX_PAYLOAD_BYTES)
		{
			return false;
		}
		$manifestItems = [];
		foreach ($items as $item)
		{
			$manifestItems[] = new ManifestItem(
				$item['documentId'], $item['versionId'], $item['bindingRevision'], $item['text'], $item['label'],
				$item['substitutions'], $item['textHash'], 'accepted',
			);
		}
		$manifest = new SubmissionManifest(
			str_repeat('x', 255), $formId, $payload['sourceRevision'], $manifestItems, $presentedAt,
		);
		$identity = PresentationValidator::identity($manifest);
		$identity['presentedAt'] = $payload['presentedAt'];

		return strlen(LedgerRepository::canonicalJson($identity))
			<= self::MAX_PAYLOAD_BYTES - self::MANIFEST_FREEZE_RESERVE_BYTES;
	}

	/** Verifies signed identity without requiring the live form for an existing frozen attempt. */
	public function readSigned(int $formId, string $token): array
	{
		try
		{
			if ($token === '' || strlen($token) > self::MAX_TOKEN_BYTES)
			{
				throw new \UnexpectedValueException('STALE_PRESENTATION');
			}
			$unsigned = (new Signer())->unsign($token, self::PURPOSE);
			$json = base64_decode($unsigned, true);
			if ($json === false || strlen($json) > 1048576) { throw new \UnexpectedValueException(); }
			$payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($payload) || ($payload['formId'] ?? null) !== $formId
				|| !is_int($payload['sourceId'] ?? null) || !is_int($payload['sourceRevision'] ?? null)
				|| !is_string($payload['securityHash'] ?? null) || !is_string($payload['nonce'] ?? null)
				|| !is_string($payload['presentedAt'] ?? null) || !is_array($payload['items'] ?? null))
			{
				throw new \UnexpectedValueException();
			}
			new DateTimeImmutable($payload['presentedAt']);

			return $payload;
		}
		catch (\Throwable) { throw new \UnexpectedValueException('STALE_PRESENTATION'); }
	}

	/** Verifies the display and returns current, locked storage snapshots to the trusted adapter. */
	public function verify(int $formId, string $token): array
	{
		try
		{
			$payload = $this->readSigned($formId, $token);
			$snapshot = $this->capture($formId, $payload['securityHash']);
			if ((int)$snapshot['source']['ID'] !== $payload['sourceId']
				|| (int)$snapshot['source']['REVISION'] !== $payload['sourceRevision']
				|| LedgerRepository::canonicalJson($snapshot['items']) !== LedgerRepository::canonicalJson($payload['items']))
			{
				throw new \UnexpectedValueException();
			}

			return $snapshot + ['payload' => $payload];
		}
		catch (\Throwable) { throw new \UnexpectedValueException('STALE_PRESENTATION'); }
	}

	private function capture(int $formId, string $securityHash, bool $lock = true): array
	{
		if ($formId <= 0 || $formId > 2147483647) { throw new \UnexpectedValueException('NOT_FOUND_OR_DENIED'); }
		$formRow = self::rows(FormTable::class, ['=ID' => $formId], $lock)[0] ?? null;
		if ($formRow === null || $formRow['ACTIVE'] !== 'Y'
			|| !hash_equals(hash('sha256', (string)$formRow['SECURITY_CODE']), $securityHash))
		{
			throw new \UnexpectedValueException('NOT_FOUND_OR_DENIED');
		}
		$form = new Form();
		if (!$form->loadOnlyForm($formId) || !$form->checkSecurityCode($formRow['SECURITY_CODE']) || !$form->isActive())
		{
			throw new \UnexpectedValueException('NOT_FOUND_OR_DENIED');
		}
		$source = self::rows(SourceTable::class, ['=FORM_ID' => $formId], $lock)[0] ?? null;
		if ($source === null || $source['STATE'] !== 'active')
		{
			throw new \UnexpectedValueException('STALE_PRESENTATION');
		}
		$bindings = self::rows(UsageTable::class, [
			'=SOURCE_ID' => (int)$source['ID'], '=DATE_END' => null,
		], $lock);
		if (count($bindings) > 100) { throw new \InvalidArgumentException('INVALID_PAYLOAD'); }
		$settings = $formRow['FORM_SETTINGS']['LEGAL_DOCUMENTS'] ?? [];
		$byDocument = [];
		foreach ($settings as $item)
		{
			if (is_array($item) && is_int($item['documentId'] ?? null))
			{
				$byDocument[$item['documentId']] = $item;
			}
		}
		$live = FormSourceGateway::getInstance()->captureLiveForm($formId);
		$items = [];
		$versions = [];
		$versionRows = $bindings === [] ? [] : self::rows(VersionTable::class, [
			'@ID' => array_values(array_unique(array_column($bindings, 'VERSION_ID'))),
		], $lock);
		$byVersion = array_column($versionRows, null, 'ID');
		foreach ($bindings as $binding)
		{
			$documentId = (int)$binding['DOCUMENT_ID'];
			$row = $byVersion[(int)$binding['VERSION_ID']] ?? null;
			if ($row === null || (int)$row['DOCUMENT_ID'] !== $documentId
				|| !in_array($row['STATE'], ['published', 'archived'], true))
			{
				throw new \UnexpectedValueException('STALE_PRESENTATION');
			}
			$version = CatalogRepository::mapVersion($row);
			$versions[] = $version;
			$substitutions = [];
			foreach ($version->substitutionSchema as $key => $type)
			{
				if (mb_strtolower($key) !== 'fields' || $type !== 'string[]')
				{
					throw new \InvalidArgumentException('INVALID_PAYLOAD');
				}
				$substitutions[$key] = $live['fields'];
			}
			$rendered = (new VersionRenderer())->render($version, $substitutions);
			$behavior = $byDocument[$documentId] ?? [];
			$items[] = [
				'documentId' => $documentId, 'versionId' => $version->id,
				'bindingRevision' => (int)$binding['REVISION'], 'label' => $rendered['label'],
				'text' => $rendered['text'], 'textHash' => $rendered['textHash'],
				'checked' => $binding['CHECKED'] === 'Y', 'required' => $binding['REQUIRED'] === 'Y',
				'notice' => is_string($behavior['notice'] ?? null) ? $behavior['notice'] : '',
				'order' => is_int($behavior['order'] ?? null) ? $behavior['order'] : PHP_INT_MAX,
				'substitutions' => $substitutions, 'contentHash' => $version->contentHash,
			];
		}
		usort($items, static fn(array $a, array $b): int =>
			[$a['order'], $a['documentId']] <=> [$b['order'], $b['documentId']]);
		foreach ($items as $position => &$item) { $item['order'] = $position; }
		unset($item);

		return ['source' => $source, 'bindings' => $bindings, 'versions' => $versions, 'items' => $items];
	}

	private static function rows(string $model, array $filter, bool $lock): array
	{
		return $lock
			? SubmissionService::currentRows($model, $filter)
			: $model::getList(['filter' => $filter, 'order' => ['ID' => 'ASC']])->fetchAll();
	}

	private static function publicItems(array $items): array
	{
		foreach ($items as &$item) { unset($item['substitutions'], $item['contentHash']); }
		unset($item);

		return $items;
	}
}
