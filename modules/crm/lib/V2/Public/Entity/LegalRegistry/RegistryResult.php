<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\LegalRegistry;

use Bitrix\Main;
use InvalidArgumentException;

final class RegistryResult extends Main\Result
{
	private const ERROR_MESSAGES = [
		'NOT_FOUND_OR_DENIED' => 'Registry item was not found or access was denied.',
		'INVALID_PAYLOAD' => 'Invalid registry payload.',
		'VERSION_IMMUTABLE' => 'Published registry version is immutable.',
		'REVISION_CONFLICT' => 'Registry revision conflict.',
		'STALE_PRESENTATION' => 'Registry presentation is stale.',
		'POLICY_UNAVAILABLE' => 'Registry policy is unavailable.',
		'IDEMPOTENCY_CONFLICT' => 'Registry submission key conflict.',
		'SUBJECT_UNRESOLVED' => 'Registry subject is unresolved.',
		'ALREADY_REVOKED' => 'Registry fact was already revoked.',
		'INVALID_STATE' => 'Invalid registry state.',
		'STORAGE_ERROR' => 'Registry storage failed.',
	];

	/**
	 * Adds an expected error without exposing caller messages or custom evidence.
	 *
	 * @param Main\Error $error
	 * @return self
	 */
	public function addError(Main\Error $error): self
	{
		$code = $error->getCode();
		if (!is_string($code) || !isset(self::ERROR_MESSAGES[$code]))
		{
			throw new InvalidArgumentException('Invalid registry error code.');
		}
		parent::addError(new Main\Error(self::ERROR_MESSAGES[$code], $code));
		$this->data = $this->filterErrorData($this->data);

		return $this;
	}

	/**
	 * Adds expected errors through the same evidence guard as a single error.
	 *
	 * @param list<Main\Error> $errors
	 * @return self
	 */
	public function addErrors(array $errors): self
	{
		foreach ($errors as $error)
		{
			$this->addError($error);
		}

		return $this;
	}

	/**
	 * Returns a detached error collection that cannot bypass error validation.
	 *
	 * @return Main\ErrorCollection
	 */
	public function getErrorCollection(): Main\ErrorCollection
	{
		return clone parent::getErrorCollection();
	}

	/**
	 * Sets only typed public payload fields, detaching array references.
	 *
	 * @param array $data
	 * @return self
	 */
	public function setData(array $data): self
	{
		$snapshot = [];
		foreach ($data as $key => $value)
		{
			$snapshot[$key] = $this->validateField($key, $value);
		}
		parent::setData($this->filterErrorData($snapshot));

		return $this;
	}

	/**
	 * Returns the public payload without evidence on validation errors.
	 *
	 * @return array
	 */
	public function getData(): array
	{
		return $this->filterErrorData(parent::getData());
	}

	/**
	 * Returns document metadata.
	 *
	 * @return ?CatalogDocument
	 */
	public function getDocument(): ?CatalogDocument
	{
		return $this->getData()['document'] ?? null;
	}

	/**
	 * Sets document metadata.
	 *
	 * @param ?CatalogDocument $value
	 * @return self
	 */
	public function setDocument(?CatalogDocument $value): self
	{
		return $this->setData(array_replace($this->data, ['document' => $value]));
	}

	/**
	 * Returns versions metadata.
	 *
	 * @return list<CatalogVersion>
	 */
	public function getVersions(): array
	{
		return $this->getData()['versions'] ?? [];
	}

	/**
	 * Sets versions metadata.
	 *
	 * @param list<CatalogVersion> $value
	 * @return self
	 */
	public function setVersions(array $value): self
	{
		return $this->setData(array_replace($this->data, ['versions' => $value]));
	}

	public function getRevision(): ?int
	{
		return $this->getData()['revision'] ?? null;
	}

	public function setRevision(?int $revision): self
	{
		return $this->setData(array_replace($this->data, ['revision' => $revision]));
	}

	public function getCardRevision(): ?int
	{
		return $this->getData()['cardRevision'] ?? null;
	}

	public function setCardRevision(?int $revision): self
	{
		return $this->setData(array_replace($this->data, ['cardRevision' => $revision]));
	}

	public function getBindings(): ?FormBindings
	{
		return $this->getData()['bindings'] ?? null;
	}

	public function setBindings(?FormBindings $bindings): self
	{
		return $this->setData(array_replace($this->data, ['bindings' => $bindings]));
	}

	public function getPresentation(): ?FormPresentation
	{
		return $this->getData()['presentation'] ?? null;
	}

	public function setPresentation(?FormPresentation $presentation): self
	{
		return $this->setData(array_replace($this->data, ['presentation' => $presentation]));
	}

	/** @return array<string, string> */
	public function getFieldErrors(): array
	{
		return $this->getData()['fieldErrors'] ?? [];
	}

	/** @param array<string, string> $errors */
	public function setFieldErrors(array $errors): self
	{
		return $this->setData(array_replace($this->data, ['fieldErrors' => $errors]));
	}

	/**
	 * Returns facts metadata.
	 *
	 * @return list<Fact>
	 */
	public function getFacts(): array
	{
		return $this->getData()['facts'] ?? [];
	}

	/**
	 * Sets facts metadata.
	 *
	 * @param list<Fact> $value
	 * @return self
	 */
	public function setFacts(array $value): self
	{
		return $this->setData(array_replace($this->data, ['facts' => $value]));
	}

	/**
	 * Returns usage metadata.
	 *
	 * @return list<FormUsage>
	 */
	public function getUsage(): array
	{
		return $this->getData()['usage'] ?? [];
	}

	/**
	 * Sets usage metadata.
	 *
	 * @param list<FormUsage> $value
	 * @return self
	 */
	public function setUsage(array $value): self
	{
		return $this->setData(array_replace($this->data, ['usage' => $value]));
	}

	/**
	 * Returns submissionId metadata.
	 *
	 * @return ?int
	 */
	public function getSubmissionId(): ?int
	{
		return $this->getData()['submissionId'] ?? null;
	}

	/**
	 * Sets submissionId metadata.
	 *
	 * @param ?int $value
	 * @return self
	 */
	public function setSubmissionId(?int $value): self
	{
		return $this->setData(array_replace($this->data, ['submissionId' => $value]));
	}

	/**
	 * Returns factIds metadata.
	 *
	 * @return list<int>
	 */
	public function getFactIds(): array
	{
		return $this->getData()['factIds'] ?? [];
	}

	/**
	 * Sets factIds metadata.
	 *
	 * @param list<int> $value
	 * @return self
	 */
	public function setFactIds(array $value): self
	{
		return $this->setData(array_replace($this->data, ['factIds' => $value]));
	}

	/**
	 * Returns state metadata.
	 *
	 * @return ?string
	 */
	public function getState(): ?string
	{
		return $this->getData()['state'] ?? null;
	}

	/**
	 * Sets state metadata.
	 *
	 * @param ?string $value
	 * @return self
	 */
	public function setState(?string $value): self
	{
		return $this->setData(array_replace($this->data, ['state' => $value]));
	}

	/**
	 * Returns nextCursor metadata.
	 *
	 * @return ?int
	 */
	public function getNextCursor(): ?int
	{
		return $this->getData()['nextCursor'] ?? null;
	}

	/**
	 * Sets nextCursor metadata.
	 *
	 * @param ?int $value
	 * @return self
	 */
	public function setNextCursor(?int $value): self
	{
		return $this->setData(array_replace($this->data, ['nextCursor' => $value]));
	}

	/**
	 * Returns totalCount metadata.
	 *
	 * @return ?int
	 */
	public function getTotalCount(): ?int
	{
		return $this->getData()['totalCount'] ?? null;
	}

	/**
	 * Sets totalCount metadata.
	 *
	 * @param ?int $value
	 * @return self
	 */
	public function setTotalCount(?int $value): self
	{
		return $this->setData(array_replace($this->data, ['totalCount' => $value]));
	}

	/** @return array<int, int> */
	public function getFormCounts(): array
	{
		return $this->getData()['formCounts'] ?? [];
	}

	/** @param array<int, int> $counts */
	public function setFormCounts(array $counts): self
	{
		return $this->setData(array_replace($this->data, ['formCounts' => $counts]));
	}

	/**
	 * Returns replay metadata.
	 *
	 * @return bool
	 */
	public function wasReplay(): bool
	{
		return $this->getData()['replay'] ?? false;
	}

	/**
	 * Sets replay metadata.
	 *
	 * @param bool $value
	 * @return self
	 */
	public function setReplay(bool $value): self
	{
		return $this->setData(array_replace($this->data, ['replay' => $value]));
	}

	/** @return list<CatalogDocument> */
	public function getDocuments(): array { return $this->getData()['documents'] ?? []; }

	/** @param list<CatalogDocument> $documents */
	public function setDocuments(array $documents): self
	{
		return $this->setData(array_replace($this->data, ['documents' => $documents]));
	}

	/** @return list<CatalogListItem> */
	public function getCatalogListItems(): array { return $this->getData()['catalogListItems'] ?? []; }

	/** @param list<CatalogListItem> $items */
	public function setCatalogListItems(array $items): self
	{
		return $this->setData(array_replace($this->data, ['catalogListItems' => $items]));
	}

	/** @return list<CatalogChoice> */
	public function getChoices(): array { return $this->getData()['choices'] ?? []; }

	/** @param list<CatalogChoice> $choices */
	public function setChoices(array $choices): self
	{
		return $this->setData(array_replace($this->data, ['choices' => $choices]));
	}

	/**
	 * @return list<array{id:int,sourceId:int,formId:int,sourceName:string,state:string,
	 *     serverReceivedAt:\DateTimeImmutable,errorCode:?string}>
	 */
	public function getPending(): array { return $this->getData()['pending'] ?? []; }

	public function setPending(array $pending): self
	{
		return $this->setData(array_replace($this->data, ['pending' => $pending]));
	}

	private function validateField(string $key, mixed $value): mixed
	{
		if ($key === 'fieldErrors')
		{
			return self::safeFieldErrors($value);
		}
		if ($key === 'formCounts')
		{
			return self::safeFormCounts($value);
		}
		$valid = match ($key)
		{
			'document' => $value === null || $value instanceof CatalogDocument,
			'bindings' => $value === null || $value instanceof FormBindings,
			'presentation' => $value === null || $value instanceof FormPresentation,
			'cardRevision' => $value === null
				|| (is_int($value) && $value > 0 && $value <= 9007199254740991),
			'submissionId', 'nextCursor', 'revision' => $value === null || self::isPositiveId($value),
			'totalCount' => $value === null || (is_int($value) && $value >= 0),
			'state' => $value === null || in_array($value, ['pending', 'committed', 'failed'], true),
			'replay' => is_bool($value),
			'documents', 'catalogListItems', 'choices', 'pending', 'versions', 'facts', 'usage', 'factIds'
				=> is_array($value) && array_is_list($value),
			default => false,
		};
		if (!$valid)
		{
			throw new InvalidArgumentException('Invalid registry result payload.');
		}
		if (!is_array($value))
		{
			return $value;
		}
		$snapshot = [];
		foreach ($value as $entry)
		{
			$validEntry = match ($key)
			{
				'documents' => $entry instanceof CatalogDocument,
				'catalogListItems' => $entry instanceof CatalogListItem,
				'choices' => $entry instanceof CatalogChoice,
				'pending' => is_array($entry),
				'versions' => $entry instanceof CatalogVersion,
				'facts' => $entry instanceof Fact,
				'usage' => $entry instanceof FormUsage,
				'factIds' => self::isPositiveId($entry),
				default => false,
			};
			if (!$validEntry)
			{
				throw new InvalidArgumentException('Invalid registry result payload.');
			}
			$snapshot[] = $key === 'pending' ? self::pendingEntry($entry) : $entry;
		}

		return $snapshot;
	}

	private static function safeFieldErrors(mixed $errors): array
	{
		if (!is_array($errors)) { throw new InvalidArgumentException('Invalid registry field errors.'); }
		$fields = CatalogFields::getDefinitions();
		$safe = [];
		foreach ($errors as $field => $code)
		{
			if (!is_string($field)
				|| !in_array($code, ['REQUIRED', 'INVALID_VALUE', 'READ_ONLY', 'UNKNOWN_FIELD'], true)
				|| (!isset($fields[$field]) && $code !== 'UNKNOWN_FIELD'))
			{
				throw new InvalidArgumentException('Invalid registry field errors.');
			}
			// Unknown input names are not reflected in the public error envelope.
			$safe[isset($fields[$field]) ? $field : '_unknown'] = $code;
		}

		return $safe;
	}

	private static function safeFormCounts(mixed $counts): array
	{
		if (!is_array($counts)) { throw new InvalidArgumentException('Invalid registry form counts.'); }
		$safe = [];
		foreach ($counts as $documentId => $count)
		{
			if (!is_int($documentId) || !self::isPositiveId($documentId)
				|| !is_int($count) || $count < 0)
			{
				throw new InvalidArgumentException('Invalid registry form counts.');
			}
			$safe[$documentId] = $count;
		}

		return $safe;
	}

	private static function pendingEntry(array $entry): array
	{
		$expected = ['id', 'sourceId', 'formId', 'sourceName', 'state', 'serverReceivedAt', 'errorCode'];
		if (count($entry) !== count($expected) || array_diff($expected, array_keys($entry)) !== [])
		{
			throw new InvalidArgumentException('Invalid pending summary.');
		}
		foreach (['id', 'sourceId', 'formId'] as $key)
		{
			if (!self::isPositiveId($entry[$key])) { throw new InvalidArgumentException('Invalid pending summary.'); }
		}
		if (!is_string($entry['sourceName']) || !in_array($entry['state'], ['pending', 'failed'], true)
			|| !$entry['serverReceivedAt'] instanceof \DateTimeImmutable
			|| ($entry['errorCode'] !== null && (!is_string($entry['errorCode'])
				|| !isset(self::ERROR_MESSAGES[$entry['errorCode']]))))
		{
			throw new InvalidArgumentException('Invalid pending summary.');
		}
		$result = [];
		foreach ($expected as $key) { $result[$key] = $entry[$key]; }
		$result['serverReceivedAt'] = $entry['serverReceivedAt']->setTimezone(new \DateTimeZone('UTC'));

		return $result;
	}

	private static function isPositiveId(mixed $value): bool
	{
		return is_int($value) && $value > 0 && $value <= 2147483647;
	}

	private function filterErrorData(array $data): array
	{
		if ($this->isSuccess())
		{
			return $data;
		}
		$allowed = ['submissionId', 'state', 'replay'];
		$errors = $this->getErrors();
		if (count($errors) === 1 && $errors[0]->getCode() === 'INVALID_PAYLOAD')
		{
			$allowed[] = 'fieldErrors';
		}
		if (count($errors) === 1 && $errors[0]->getCode() === 'ALREADY_REVOKED')
		{
			$allowed[] = 'factIds';
			$allowed[] = 'facts';
		}

		return array_intersect_key($data, array_flip($allowed));
	}
}
