<?php

declare(strict_types=1);

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\CatalogDocument;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\CatalogFields;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\CatalogVersion;
use Bitrix\Crm\V2\Public\Provider\LegalRegistry\CatalogProvider;
use Bitrix\Crm\V2\Public\Provider\LegalRegistry\UsageProvider;
use Bitrix\Crm\WebForm\Manager;
use Bitrix\Main\Loader;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CrmLegalDocumentEditComponent extends CBitrixComponent
{
	public function executeComponent(): void
	{
		if (!Loader::includeModule('crm')) { return; }
		$userId = $this->currentUserId();
		$provider = new CatalogProvider();
		if (!$provider->getSchemaAccess($userId)->isSuccess()) { return; }

		$documentId = self::positiveId($this->arParams['DOCUMENT_ID'] ?? null);
		$versionId = self::positiveId($this->arParams['VERSION_ID'] ?? null);
		if (($this->arParams['DOCUMENT_ID'] ?? null) !== null && $documentId === null) { return; }
		if (($this->arParams['VERSION_ID'] ?? null) !== null && $versionId === null) { return; }
		if ($versionId !== null && $documentId === null) { return; }
		$context = ($this->arParams['CONTEXT'] ?? null) === 'form' ? 'form' : 'catalog';
		$formId = $context === 'form' ? self::positiveId($this->arParams['FORM_ID'] ?? null) : null;
		if ($context === 'form' && $formId === null)
		{
			return;
		}
		$sourceReadable = $context !== 'form' || ($formId !== null
			&& (new UsageProvider())->getBindingsByForm($userId, $formId)->isSuccess());

		$document = null;
		$version = null;
		$cardRevision = null;
		if ($documentId !== null)
		{
			$result = $provider->getCard($userId, $documentId, $versionId);
			if (!$result->isSuccess()) { return; }
			$document = $result->getDocument();
			$version = $result->getVersions()[0] ?? null;
			$cardRevision = $result->getCardRevision();
			if ($document === null || $cardRevision === null) { return; }
		}

		$status = $document?->archived ? 'archived' : ($version?->state ?? 'draft');
		$canEdit = $userId > 0 && Container::getInstance()->getUserPermissions($userId)
			->webForm()->canEdit() && $sourceReadable && $status !== 'archived';
		$this->arResult = [
			'VIEW' => [
				'schema' => CatalogFields::getDefinitions(),
				'versions' => array_map(static fn(CatalogVersion $item): array => [
					'id' => $item->id, 'number' => $item->number, 'state' => $item->state,
				], $document?->versions ?? []),
				'snapshot' => [
					'documentId' => $documentId,
					'versionId' => $version?->id,
					'revision' => $cardRevision,
					'draft' => self::draft($document, $version),
					'errors' => [], 'error' => null, 'loading' => false,
				],
				'status' => $status,
				'capabilities' => [
					'canRead' => true,
					'canEdit' => $canEdit,
					'canPublish' => $canEdit && $status === 'draft' && $version !== null,
					'canArchive' => $canEdit && $status === 'published',
				],
				'context' => $context,
				'message' => null,
			],
			'SOURCE_URL' => $sourceReadable && $formId !== null ? self::sourceUrl($formId) : null,
		];
		$this->IncludeComponentTemplate();
	}

	protected function currentUserId(): int
	{
		$user = $GLOBALS['USER'] ?? null;

		return $user instanceof CUser ? (int)$user->GetID() : 0;
	}

	private static function positiveId(mixed $value): ?int
	{
		if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) { return null; }
		$id = (int)$value;

		return $id > 0 && $id <= 2147483647 ? $id : null;
	}

	private static function sourceUrl(int $formId): ?string
	{
		$url = Manager::getEditUrl($formId);

		return is_string($url) && str_starts_with($url, '/') && !str_starts_with($url, '//')
			? $url : null;
	}

	private static function draft(?CatalogDocument $document, ?CatalogVersion $version): array
	{
		$draft = [];
		foreach (CatalogFields::getDefinitions() as $name => $field)
		{
			$draft[$name] = $field['default'];
		}
		$settings = $version?->draftSettings;
		$rule = $version?->expiryRule;
		$draft['name'] = $document?->name ?? '';
		$draft['type'] = $settings?->type ?? 'C';
		$draft['provider'] = $settings?->provider;
		$draft['text'] = $version?->template ?? '';
		$draft['html'] = $settings?->html ?? false;
		$draft['label'] = $version?->label ?? '';
		$draft['url'] = $version?->displayUrl;
		$draft['templateFields'] = $settings?->templateFields ?? [];
		$draft['legalEntityId'] = $document?->metadata?->legalEntityId;
		$draft['languageId'] = $version?->languageId ?? (defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru');
		$draft['purpose'] = $document?->metadata?->purpose;
		$draft['expiryRule'] = $rule?->kind ?? 'none';
		$draft['validFrom'] = $version?->validFrom?->format('Y-m-d');
		$draft['validTo'] = $version?->validTo?->format('Y-m-d');
		$draft['expiryPeriodUnit'] = $rule?->unit;
		$draft['expiryPeriodAmount'] = $rule?->amount;
		$draft['expiryDate'] = $rule?->date;
		$draft['tags'] = $document?->metadata?->tags ?? [];
		$draft['responsibleId'] = $document?->metadata?->responsibleId;
		$draft['documentId'] = $document?->id;
		$draft['versionId'] = $version?->id;
		$draft['versionNumber'] = $version?->number;
		$currentVersionId = null;
		foreach ($document?->versions ?? [] as $candidate)
		{
			if ($candidate->state === 'published') { $currentVersionId = $candidate->id; }
		}
		$draft['currentVersionId'] = $currentVersionId;

		return $draft;
	}
}
