<?php

declare(strict_types=1);

use Bitrix\Crm\V2\Public\Provider\LegalRegistry\UsageProvider;
use Bitrix\Main\Loader;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CrmLegalDocumentUsageComponent extends CBitrixComponent
{
	public function executeComponent(): void
	{
		if (!Loader::includeModule('crm')) { return; }
		$documentId = self::positiveId($this->arParams['DOCUMENT_ID'] ?? null);
		if ($documentId === null) { return; }
		$cursor = self::positiveId($this->arParams['CURSOR'] ?? null);
		if (($this->arParams['CURSOR'] ?? null) !== null && $cursor === null) { return; }
		$result = (new UsageProvider())->getByDocument($this->currentUserId(), $documentId, $cursor);
		if (!$result->isSuccess()) { return; }
		$history = [];
		foreach ($result->getUsage() as $usage)
		{
			foreach ($usage->intervals as $interval)
			{
				$history[] = [
					'documentId' => $usage->documentId,
					'formId' => $usage->formId,
					'versionId' => $interval['versionId'],
					'versionNumber' => $usage->versionNumbers[$interval['versionId']] ?? null,
					'revision' => $interval['revision'],
					'sourceId' => $usage->sourceId,
					'sourceName' => $usage->formName,
					'sourceUrl' => $usage->formUrl,
					'sourceStatus' => $usage->sourceState === 'deleted'
						|| ($usage->sourceState === 'active' && $usage->deleted)
						? 'deleted' : ($usage->sourceState === 'inactive' ? 'archived' : 'active'),
					'startedAt' => $interval['startsAt']->format(DATE_ATOM),
					'endedAt' => $interval['endsAt']?->format(DATE_ATOM),
					'snapshot' => $interval['formNameSnapshot'],
				];
			}
		}
		$this->arResult = ['HISTORY' => $history, 'DOCUMENT_ID' => $documentId,
			'NEXT_CURSOR' => $result->getNextCursor()];
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
}
