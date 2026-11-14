<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Controller\LegalRegistry;

use Bitrix\Crm\V2\Internal\Service\WebForm\FormScriptBuilder;
use Bitrix\Crm\V2\Public\Command\LegalRegistry\FormBindingsCommand;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\FormBindings;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\FormUsage;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Crm\V2\Public\Provider\LegalRegistry\UsageProvider;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;

/** AJAX transport for current editor bindings and historical usage. */
final class Usage extends Controller
{
	public function __construct(?HttpRequest $request = null, private readonly ?int $userId = null)
	{
		parent::__construct($request);
	}

	public function configureActions(): array
	{
		return ['save' => ['prefilters' => [new ActionFilter\Authentication(),
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]), new ActionFilter\Csrf()]]];
	}

	public function getByFormAction(int $formId): array
	{
		$result = (new UsageProvider())->getBindingsByForm($this->actorId(), $formId);
		$bindings = $result->getBindings();

		return self::envelope($result, $bindings === null ? null
			: ['items' => $bindings->items, 'expectedSourceRevision' => $bindings->expectedSourceRevision]);
	}

	public function saveAction(int $formId, array $legalDocuments): array
	{
		try
		{
			if (array_diff(array_keys($legalDocuments), ['items', 'expectedSourceRevision']) !== []
				|| !array_key_exists('items', $legalDocuments)
				|| !array_key_exists('expectedSourceRevision', $legalDocuments))
			{
				return self::envelope(self::error('INVALID_PAYLOAD'));
			}
			$bindings = new FormBindings($legalDocuments['items'], $legalDocuments['expectedSourceRevision']);
			$result = (new FormBindingsCommand($this->actorId(), $formId, $bindings))->run();
			if ($result->isSuccess())
			{
				$pool = Application::getInstance()->getConnectionPool();
				$pool->useMasterOnly(true);
				try
				{
					if (!(new FormScriptBuilder())->build($formId))
					{
						return self::envelope(self::error('STORAGE_ERROR'));
					}
				}
				finally { $pool->useMasterOnly(false); }
			}

			return self::envelope($result, ['sourceRevision' => $result->getRevision()], $result->getRevision());
		}
		catch (\InvalidArgumentException|\TypeError) { return self::envelope(self::error('INVALID_PAYLOAD')); }
		catch (\Throwable) { return self::envelope(self::error('STORAGE_ERROR')); }
	}

	public function getByDocumentAction(int $documentId, ?int $cursor = null, int $limit = 50): array
	{
		$result = (new UsageProvider())->getByDocument($this->actorId(), $documentId, $cursor, $limit);
		if (!$result->isSuccess()) { return self::envelope($result); }

		return self::envelope($result, ['items' => self::historyItems($result->getUsage()),
			'nextCursor' => $result->getNextCursor(), 'totalCount' => $result->getTotalCount()]);
	}

	/** @param list<FormUsage> $usages */
	private static function historyItems(array $usages): array
	{
		$items = [];
		foreach ($usages as $usage)
		{
			foreach ($usage->intervals as $interval)
			{
				$items[] = ['documentId' => $usage->documentId, 'versionId' => $interval['versionId'],
					'formId' => $usage->formId, 'versionNumber' => $usage->versionNumbers[$interval['versionId']] ?? null,
					'revision' => $interval['revision'],
					'sourceId' => $usage->sourceId, 'sourceName' => $usage->formName,
					'sourceUrl' => $usage->formUrl,
					'sourceStatus' => $usage->sourceState === 'deleted'
						|| ($usage->sourceState === 'active' && $usage->deleted)
							? 'deleted' : ($usage->sourceState === 'inactive' ? 'archived' : 'active'),
					'startedAt' => $interval['startsAt']->format(DATE_ATOM),
					'endedAt' => $interval['endsAt']?->format(DATE_ATOM),
					'snapshot' => $interval['formNameSnapshot']];
			}
		}

		return $items;
	}

	private function actorId(): int
	{
		return $this->userId ?? (int)($this->getCurrentUser()?->getId() ?? 0);
	}

	private static function envelope(RegistryResult $result, mixed $data = null, ?int $revision = null): array
	{
		if (!$result->isSuccess())
		{
			$errors = [];
			foreach ($result->getErrors() as $error)
			{
				$errors[] = ['code' => $error->getCode(), 'message' => $error->getMessage()];
			}

			return ['data' => null, 'errors' => $errors];
		}
		$response = ['data' => $data, 'errors' => []];
		if ($revision !== null) { $response['revision'] = $revision; }

		return $response;
	}

	private static function error(string $code): RegistryResult
	{
		return (new RegistryResult())->addError(new Error('', $code));
	}
}
