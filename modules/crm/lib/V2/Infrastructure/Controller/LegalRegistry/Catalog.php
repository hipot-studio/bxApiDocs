<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Controller\LegalRegistry;

use Bitrix\Crm\V2\Public\Command\LegalRegistry\CatalogCommand;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\CatalogDocument;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\CatalogListItem;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\CatalogFields;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\CatalogMetadata;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\CatalogVersion;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\DraftSettings;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\ExpiryRule;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Crm\V2\Public\Provider\LegalRegistry\CatalogProvider;
use Bitrix\Crm\V2\Public\Provider\LegalRegistry\UsageProvider;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Provider\Params\FilterInterface;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Web\Uri;
use Bitrix\Crm\WebForm\Manager;

/** AJAX transport for the CRM legal document card. */
final class Catalog extends Controller
{
	public function __construct(?HttpRequest $request = null, private readonly ?int $userId = null)
	{
		parent::__construct($request);
	}

	public function configureActions(): array
	{
		$post = [new ActionFilter\Authentication(),
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]), new ActionFilter\Csrf()];

		return [
			'save' => ['prefilters' => $post],
			'publish' => ['prefilters' => $post],
			'archiveVersion' => ['prefilters' => $post],
			'createDraft' => ['prefilters' => $post],
		];
	}

	public function schemaAction(): array
	{
		$result = (new CatalogProvider())->getSchemaAccess($this->actorId());

		return self::envelope($result, CatalogFields::getDefinitions());
	}

	public function getAction(int $documentId, ?int $versionId = null): array
	{
		$result = (new CatalogProvider())->getCard($this->actorId(), $documentId, $versionId);

		return self::envelope($result, self::card($result), $result->getCardRevision());
	}

	public function listAction(array $filters = [], int $limit = 50, ?int $cursor = null): array
	{
		try
		{
			if ($limit < 1 || $limit > 100 || ($cursor !== null && ($cursor < 1 || $cursor > 2147483647))
				|| array_diff(array_keys($filters),
				['name', 'id', 'responsible', 'tags', 'query']) !== [])
			{
				return self::envelope(self::error('INVALID_PAYLOAD'));
			}
			$search = $filters['query'] ?? '';
			if (!is_string($search)) { return self::envelope(self::error('INVALID_PAYLOAD')); }
			$conditions = [];
			foreach (['name' => 'NAME', 'id' => 'ID', 'responsible' => 'RESPONSIBLE_ID',
				'tags' => 'TAGS'] as $input => $column)
			{
				$value = $filters[$input] ?? null;
				if ($value === null || $value === '') { continue; }
				if (in_array($input, ['id', 'responsible'], true))
				{
					if ((!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1))
						|| (int)$value <= 0 || (int)$value > 2147483647)
					{
						return self::envelope(self::error('INVALID_PAYLOAD'));
					}
					$value = (int)$value;
				}
				elseif (!is_string($value)) { return self::envelope(self::error('INVALID_PAYLOAD')); }
				$conditions[] = [$column, $value];
			}
			$filter = new class($conditions, $cursor) implements FilterInterface {
				public function __construct(private readonly array $conditions, private readonly ?int $cursor) {}
				public function prepareFilter(): ConditionTree
				{
					$tree = new ConditionTree();
					foreach ($this->conditions as [$column, $value]) { $tree->where($column, '=', $value); }
					if ($this->cursor !== null) { $tree->where('ID', '>', $this->cursor); }

					return $tree;
				}
			};
			$result = (new CatalogProvider())->getListItems($this->actorId(),
				new GridParams(new Pager($limit), $filter), $search);
			if (!$result->isSuccess()) { return self::envelope($result); }
			$items = $result->getCatalogListItems();
			$counts = (new UsageProvider())->getCountByDocuments($this->actorId(),
				array_map(static fn(CatalogListItem $item): int => $item->documentId, $items));
			if (!$counts->isSuccess()) { return self::envelope($counts); }
			$rows = [];
			foreach ($items as $item)
			{
				$count = $counts->getFormCounts()[$item->documentId] ?? 0;
				$rows[] = ['documentId' => $item->documentId, 'name' => $item->name,
					'status' => $item->status, 'versionId' => $item->versionId,
					'versionNumber' => $item->versionNumber, 'currentVersionId' => $item->currentVersionId,
					'responsible' => $item->responsibleId === null ? '' : (string)$item->responsibleId,
					'tags' => $item->tags, 'permittedFormsCount' => $count,
					'formsUrl' => $count > 0 ? self::usageUrl($item->documentId) : null];
			}

			return self::envelope($result, $rows) + ['nextCursor' => $result->getNextCursor()];
		}
		catch (\Throwable) { return self::envelope(self::error('STORAGE_ERROR')); }
	}

	public function choicesAction(int $formId, ?int $cursor = null, int $limit = 50, string $query = ''): array
	{
		$result = (new CatalogProvider())->getChoicesByForm($this->actorId(), $formId, $cursor, $limit, $query);
		if (!$result->isSuccess()) { return self::envelope($result); }
		$choices = [];
		foreach ($result->getChoices() as $choice)
		{
			$choices[] = [
				'documentId' => $choice->documentId,
				'versionId' => $choice->versionId,
				'number' => $choice->number,
				'name' => $choice->name,
				'status' => $choice->status,
				'eligible' => $choice->eligible,
			];
		}

		return self::envelope($result, ['choices' => $choices, 'nextCursor' => $result->getNextCursor()]);
	}

	public function saveAction(array $draft, ?int $documentId = null, ?int $versionId = null,
		?int $expectedRevision = null): array
	{
		if (($draft['html'] ?? null) === 'true')
		{
			$draft['html'] = true;
		}
		elseif (($draft['html'] ?? null) === 'false')
		{
			$draft['html'] = false;
		}
		foreach (['legalEntityId', 'responsibleId', 'expiryPeriodAmount'] as $field)
		{
			$value = $draft[$field] ?? null;
			if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1
				&& strlen($value) <= 10 && (int)$value <= 2147483647)
			{
				$draft[$field] = (int)$value;
			}
		}
		foreach (['legalEntityId', 'responsibleId'] as $field)
		{
			if (($draft[$field] ?? null) === '')
			{
				$draft[$field] = null;
			}
		}
		if (($draft['url'] ?? null) === '')
		{
			$draft['url'] = null;
		}
		foreach (array_keys($draft) as $field)
		{
			if (!is_string($field))
			{
				return self::envelope((new RegistryResult())->setFieldErrors(['_unknown' => 'UNKNOWN_FIELD'])
					->addError(new Error('', 'INVALID_PAYLOAD')));
			}
		}
		$fields = CatalogFields::validateInput($draft);
		if ($fields !== [])
		{
			return self::envelope((new RegistryResult())->setFieldErrors($fields)
				->addError(new Error('', 'INVALID_PAYLOAD')));
		}
		try
		{
			if (($documentId === null) !== ($expectedRevision === null)
				|| ($documentId === null && $versionId !== null))
			{
				return self::envelope(self::error('INVALID_PAYLOAD'));
			}
			$metadata = new CatalogMetadata($draft['legalEntityId'] ?? null, $draft['purpose'] ?? null,
				$draft['tags'] ?? [], $draft['responsibleId'] ?? null);
			$settings = new DraftSettings($draft['type'] ?? 'C', $draft['provider'] ?? null,
				$draft['html'] ?? false, $draft['templateFields'] ?? []);
			$stored = $documentId !== null && $versionId !== null
				? (new CatalogProvider())->getCard($this->actorId(), $documentId, $versionId) : null;
			if ($stored !== null && !$stored->isSuccess()) { return self::envelope($stored); }
			$old = $stored?->getVersions()[0] ?? null;
			$text = $draft['text'] ?? '';
			$version = new CatalogVersion($versionId, $documentId, $old?->number, null, 'draft',
				$draft['languageId'], $text, $draft['label'] ?? '', $old?->substitutionSchema ?? [],
				hash('sha256', $text), self::expiry($draft), self::date($draft['validFrom'] ?? null),
				self::date($draft['validTo'] ?? null, true), $draft['url'] ?? null, null, null, $settings);
			$command = $documentId === null
				? new CatalogCommand($this->actorId(), 'createDocumentWithDraft', null, $version, $draft['name'])
				: new CatalogCommand($this->actorId(), 'saveCard', $documentId, $version, $draft['name'],
					$expectedRevision);
			$result = $command->withMetadata($metadata)->withDraftSettings($settings)->run();
			if (!$result->isSuccess()) { return self::envelope($result); }
			$saved = $result->getVersions()[0] ?? null;
			$document = $result->getDocument();
			if ($saved === null || $document === null) { return self::envelope(self::error('STORAGE_ERROR')); }
			$revision = $result->getCardRevision() ?? $document->cardRevision($saved);

			return self::envelope($result,
				['documentId' => $document->id, 'versionId' => $saved->id, 'revision' => $revision], $revision);
		}
		catch (\InvalidArgumentException) { return self::envelope(self::error('INVALID_PAYLOAD')); }
		catch (\Throwable) { return self::envelope(self::error('STORAGE_ERROR')); }
	}

	public function verifyVersionAction(int $documentId, int $versionId, ?int $expectedSourceRevision = null): array
	{
		if ($expectedSourceRevision !== null && ($expectedSourceRevision <= 0
			|| $expectedSourceRevision > 2147483647))
		{
			return self::envelope(self::error('INVALID_PAYLOAD'));
		}
		$result = (new CatalogProvider())->verifySelectableVersion($this->actorId(), $documentId, $versionId);

		return self::envelope($result, ['documentId' => $documentId, 'versionId' => $versionId,
			'expectedSourceRevision' => $expectedSourceRevision]);
	}

	public function publishAction(int $documentId, int $versionId, int $expectedRevision): array
	{
		return $this->transition('publishCard', $documentId, $versionId, $expectedRevision);
	}

	public function archiveVersionAction(int $documentId, int $versionId, int $expectedRevision): array
	{
		return $this->transition('archiveVersionCard', $documentId, $versionId, $expectedRevision);
	}

	public function createDraftAction(int $documentId, int $sourceVersionId): array
	{
		$source = (new CatalogProvider())->getCard($this->actorId(), $documentId, $sourceVersionId);
		if (!$source->isSuccess()) { return self::envelope($source); }
		$version = $source->getVersions()[0] ?? null;
		if ($version === null || $version->state !== 'published')
		{
			return self::envelope(self::error('INVALID_STATE'));
		}
		$draft = new CatalogVersion(null, $documentId, null, null, 'draft', $version->languageId,
			$version->template, $version->label, $version->substitutionSchema, $version->contentHash,
			$version->expiryRule, $version->validFrom, $version->validTo, $version->displayUrl,
			draftSettings: $version->draftSettings);
		$result = (new CatalogCommand($this->actorId(), 'createDraft', $documentId, $draft))
			->withDraftSettings($version->draftSettings)->run();
		if (!$result->isSuccess()) { return self::envelope($result); }
		$created = $result->getVersions()[0] ?? null;
		if ($created === null) { return self::envelope(self::error('STORAGE_ERROR')); }
		$card = (new CatalogProvider())->getCard($this->actorId(), $documentId, $created->id);
		if (!$card->isSuccess()) { return self::envelope($card); }

		return self::envelope($card, ['documentId' => $documentId, 'versionId' => $created->id,
			'revision' => $card->getCardRevision()], $card->getCardRevision());
	}

	private function transition(string $operation, int $documentId, int $versionId, int $expectedRevision): array
	{
		$card = (new CatalogProvider())->getCard($this->actorId(), $documentId, $versionId);
		if (!$card->isSuccess()) { return self::envelope($card); }
		$version = $card->getVersions()[0] ?? null;
		if ($version === null) { return self::envelope(self::error('NOT_FOUND_OR_DENIED')); }
		$result = (new CatalogCommand($this->actorId(), $operation, $documentId, $version,
			expectedRevision: $expectedRevision))->run();
		if (!$result->isSuccess()) { return self::envelope($result); }
		$updated = (new CatalogProvider())->getCard($this->actorId(), $documentId, $versionId);
		if (!$updated->isSuccess()) { return self::envelope($updated); }

		return self::envelope($updated, ['documentId' => $documentId, 'versionId' => $versionId,
			'revision' => $updated->getCardRevision()], $updated->getCardRevision());
	}

	private function actorId(): int
	{
		return $this->userId ?? (int)($this->getCurrentUser()?->getId() ?? 0);
	}

	private static function card(RegistryResult $result): ?array
	{
		$document = $result->getDocument();
		if (!$result->isSuccess() || $document === null) { return null; }
		$version = $result->getVersions()[0] ?? null;
		$settings = $version?->draftSettings;
		$rule = $version?->expiryRule;
		$draft = [
			'name' => $document->name, 'type' => $settings?->type ?? 'C',
			'provider' => $settings?->provider, 'text' => $version?->template ?? '',
			'html' => $settings?->html ?? false, 'label' => $version?->label ?? '',
			'url' => $version?->displayUrl, 'templateFields' => $settings?->templateFields ?? [],
			'legalEntityId' => $document->metadata?->legalEntityId,
			'languageId' => $version?->languageId ?? (defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru'),
			'purpose' => $document->metadata?->purpose, 'expiryRule' => $rule?->kind ?? 'none',
			'validFrom' => $version?->validFrom?->format('Y-m-d'),
			'validTo' => $version?->validTo?->format('Y-m-d'),
			'expiryPeriodUnit' => $rule?->unit, 'expiryPeriodAmount' => $rule?->amount,
			'expiryDate' => $rule?->date, 'tags' => $document->metadata?->tags ?? [],
			'responsibleId' => $document->metadata?->responsibleId,
			'documentId' => $document->id, 'versionId' => $version?->id,
			'versionNumber' => $version?->number,
			'currentVersionId' => self::currentVersionId($document),
		];

		return ['documentId' => $document->id, 'versionId' => $version?->id,
			'revision' => $result->getCardRevision(), 'draft' => $draft];
	}

	private static function currentVersionId(CatalogDocument $document): ?int
	{
		$current = null;
		foreach ($document->versions as $version)
		{
			if ($version->state === 'published') { $current = $version->id; }
		}

		return $current;
	}

	private static function expiry(array $draft): ExpiryRule
	{
		return match ($draft['expiryRule'] ?? 'none')
		{
			'period' => new ExpiryRule('period', $draft['expiryPeriodUnit'], $draft['expiryPeriodAmount']),
			'date' => new ExpiryRule('date', date: $draft['expiryDate']),
			default => new ExpiryRule('none'),
		};
	}

	private static function date(?string $value, bool $end = false): ?\DateTimeImmutable
	{
		if ($value === null || $value === '') { return null; }
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
		if (!$date) { throw new \InvalidArgumentException('Invalid date.'); }

		return $end ? $date->setTime(23, 59, 59) : $date;
	}

	private static function usageUrl(int $documentId): ?string
	{
		$path = Manager::getUrl();
		if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//'))
		{
			return null;
		}

		return (string)(new Uri($path))->addParams(['legal_document' => 'usage', 'documentId' => $documentId]);
	}

	private static function envelope(RegistryResult $result, mixed $data = null, ?int $revision = null): array
	{
		if (!$result->isSuccess())
		{
			$errors = [];
			foreach ($result->getFieldErrors() as $field => $code)
			{
				$errors[] = ['code' => $code, 'field' => $field, 'message' => 'Invalid registry payload.'];
			}
			if ($errors === [])
			{
				foreach ($result->getErrors() as $error)
				{
					$errors[] = ['code' => $error->getCode(), 'message' => $error->getMessage()];
				}
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
