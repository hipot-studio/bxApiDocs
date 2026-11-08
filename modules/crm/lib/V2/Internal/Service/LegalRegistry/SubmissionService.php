<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\LegalRegistry;

use Bitrix\Crm\V2\Internal\Model\LegalRegistry\SourceTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\SubmissionTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\UsageTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\VersionTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\FactTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\EventTable;
use Bitrix\Crm\V2\Internal\Repository\LegalRegistry\{CatalogRepository, LedgerRepository};
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\ExpiryRule;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\Fact;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\SubmissionManifest;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\SubmissionResolution;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\SubjectReference;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\TrustedSubmissionContext;
use Bitrix\Main\{Application, Error};
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use DateTimeImmutable;
use DateTimeZone;

class SubmissionService
{
	public static function getInstance(): self
	{
		$locator = ServiceLocator::getInstance();
		$service = $locator->has(self::class) ? $locator->get(self::class) : null;

		return $service instanceof self ? $service : new self();
	}

	public function execute(TrustedSubmissionContext $context, string $operation, SubmissionManifest $manifest,
		?SubmissionResolution $resolution = null): RegistryResult
	{
		$policy = PolicyGate::getInstance()->requireCollection();
		if (!$policy->isSuccess()) { return $policy; }
		try
		{
			if (!in_array($operation, ['prepare', 'complete', 'markFailed'], true)
				|| ($operation !== 'complete' && $resolution !== null)) { return self::error('INVALID_PAYLOAD'); }
			$proof = (new PresentationValidator())->verify($context, $manifest);
			$externalLevel = (new \ReflectionProperty(Application::getConnection(), 'transactionLevel'))
				->getValue(Application::getConnection())
			;

			return self::transaction(function () use ($proof, $operation, $manifest, $resolution, $externalLevel)
				: RegistryResult {
				$source = self::currentRows(SourceTable::class, ['=FORM_ID' => $manifest->formId])[0] ?? null;
				if ($source === null || (int)$source['ID'] !== $proof['sourceId']) { return self::error('STALE_PRESENTATION'); }
				$row = self::currentRows(SubmissionTable::class, ['=SOURCE_ID' => $source['ID'],
					'=SUBMISSION_KEY' => $manifest->submissionKey])[0] ?? null;
				if ($row !== null && !hash_equals($row['PAYLOAD_HASH'], PresentationValidator::identityHash($manifest)))
				{
					return self::error('IDEMPOTENCY_CONFLICT');
				}
				if ($row === null)
				{
					if ($operation !== 'prepare') { return self::error('NOT_FOUND_OR_DENIED'); }
					// Releasing a savepoint cannot make a freeze durable before the caller's CRM transaction.
					if ($externalLevel > 0) { return self::error('INVALID_STATE'); }
					$live = FormSourceGateway::getInstance()->captureLiveForm($manifest->formId);
					$bindings = self::currentRows(UsageTable::class, ['=SOURCE_ID' => $source['ID'], '=DATE_END' => null]);
					$versions = []; $snapshots = [];
					foreach ($manifest->items as $item)
					{
						$version = self::currentRows(VersionTable::class, ['=ID' => $item->versionId])[0] ?? null;
						if ($version === null || ($proof['versions'][(string)$item->versionId] ?? null) !== $version['CONTENT_HASH'])
						{
							return self::error('STALE_PRESENTATION');
						}
						$versions[] = CatalogRepository::mapVersion($version);
						$snapshots[(string)$item->versionId] = ['documentId' => (int)$version['DOCUMENT_ID'],
							'number' => (int)$version['NUMBER'], 'languageId' => $version['LANGUAGE_ID'],
							'contentHash' => $version['CONTENT_HASH'],
							'rule' => json_decode($version['EXPIRY_RULE'], true, 32, JSON_THROW_ON_ERROR)];
					}
					(new PresentationValidator())->validateCurrent($manifest, $source, $bindings, $versions);
					$timezone = $this->portalTimezone();
					$p = (new LedgerRepository())->prepareUnique($manifest);
					if (!$p->isSuccess()) { return $p; }
					$row = self::currentRows(SubmissionTable::class, ['=ID' => $p->getSubmissionId()])[0];
					$frozen = json_decode($row['MANIFEST'], true, 512, JSON_THROW_ON_ERROR);
					$frozen['registryFreeze'] = ['schemaVersion' => 1, 'timezone' => $timezone,
						'sourceName' => $live['name'], 'versions' => (object)$snapshots];
					$json = LedgerRepository::canonicalJson($frozen);
					if (strlen($json) > 1048576) { return self::error('INVALID_PAYLOAD'); }
					$saved = SubmissionTable::update((int)$row['ID'], ['MANIFEST' => $json]);
					if (!$saved->isSuccess()) { return self::error('STORAGE_ERROR'); }
					$row['MANIFEST'] = $json;

					return $this->result($row, false);
				}
				if ($operation === 'prepare') { return $this->result($row, true); }
				if ($operation === 'markFailed')
				{
					if ($row['STATE'] !== 'pending') { return self::error('INVALID_STATE'); }
					$saved = SubmissionTable::update((int)$row['ID'], ['STATE' => 'failed', 'ERROR_CODE' => 'STORAGE_ERROR']);

					return $saved->isSuccess()
						? (new RegistryResult())->setSubmissionId((int)$row['ID'])->setState('failed') : self::error('STORAGE_ERROR');
				}
				try
				{
					if ($resolution === null) { throw new \UnexpectedValueException(); }
					(new SubjectResolver())->resolve($resolution, ['sourceId' => (int)$row['SOURCE_ID'],
						'submissionId' => (int)$row['ID'], 'submissionKeyBase64' => base64_encode($row['SUBMISSION_KEY']),
						'payloadHash' => $row['PAYLOAD_HASH']]);
				}
				catch (\UnexpectedValueException)
				{
					return self::error('SUBJECT_UNRESOLVED')->setSubmissionId((int)$row['ID'])->setState($row['STATE']);
				}
				if ($row['STATE'] === 'committed') { return $this->result($row, true); }
				if ($row['STATE'] !== 'pending') { return self::error('INVALID_STATE'); }
				$frozen = self::frozen($row); $facts = [];
				$acceptedAt = self::immutableDate($row['SERVER_RECEIVED_AT']);
				foreach ($frozen['items'] as $item)
				{
					if ($item['answer'] !== 'accepted') { continue; }
					$version = $frozen['registryFreeze']['versions'][(string)$item['versionId']];
					$rule = new ExpiryRule(...$version['rule']); $zone = $frozen['registryFreeze']['timezone'];
					$expires = (new ExpiryCalculator())->calculate($rule, $acceptedAt, $zone);
					$facts[] = ['DOCUMENT_ID' => $item['documentId'], 'VERSION_ID' => $item['versionId'],
						'EFFECTIVE_TEXT' => $item['effectiveText'], 'EFFECTIVE_LABEL' => $item['effectiveLabel'],
						'SUBSTITUTIONS' => LedgerRepository::canonicalJson((object)$item['substitutions']),
						'TEXT_HASH' => $item['textHash'], 'RULE_SNAPSHOT' => LedgerRepository::canonicalJson($version['rule']),
						'TIMEZONE' => $zone, 'EXPIRES_AT' => $expires === null ? null
							: \Bitrix\Main\Type\DateTime::createFromPhp(\DateTime::createFromImmutable($expires))];
				}
				$committed = (new LedgerRepository())->commitBatch((int)$row['ID'], $resolution, $facts);
				if (!$committed->isSuccess()) { return $committed; }
				$row = self::currentRows(SubmissionTable::class, ['=ID' => $row['ID']])[0];

				return $this->result($row, false);
			});
		}
		catch (\InvalidArgumentException|\JsonException) { return self::error('INVALID_PAYLOAD'); }
		catch (\UnexpectedValueException $e)
		{
			return self::error(in_array($e->getMessage(), ['STALE_PRESENTATION', 'NOT_FOUND_OR_DENIED'], true)
				? $e->getMessage() : 'INVALID_PAYLOAD');
		}
		catch (\Throwable) { return self::error('STORAGE_ERROR'); }
	}

	protected function portalTimezone(): string
	{
		$zone = Option::get('main', 'default_time_zone', '') ?: date_default_timezone_get();
		if (!in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true))
		{
			throw new \InvalidArgumentException('INVALID_PAYLOAD');
		}

		return $zone;
	}

	private function result(array $submission, bool $replay): RegistryResult
	{
		$ids = []; $facts = [];
		foreach (self::currentRows(FactTable::class, ['=SUBMISSION_ID' => $submission['ID']]) as $row)
		{
			$ids[] = (int)$row['ID']; $facts[] = self::hydrateFact($row, $submission);
		}

		return (new RegistryResult())->setSubmissionId((int)$submission['ID'])->setState($submission['STATE'])
			->setFactIds($ids)->setFacts($facts)->setReplay($replay)
		;
	}

	/** Called only after presentation authority or staff ACL has been checked. */
	public static function hydrateFact(array $row, array $submission, ?array $preloaded = null): Fact
	{
		$frozen = json_decode($submission['MANIFEST'], true, 512, JSON_THROW_ON_ERROR);
		$metadata = $frozen['registryFreeze'] ?? null;
		$version = $metadata['versions'][(string)$row['VERSION_ID']] ?? null;
		if ($version === null)
		{
			$native = $preloaded !== null ? $preloaded['version']
				: self::currentRows(VersionTable::class, ['=ID' => $row['VERSION_ID']])[0];
			$version = ['number' => (int)$native['NUMBER'], 'languageId' => $native['LANGUAGE_ID'],
				'contentHash' => $native['CONTENT_HASH']];
		}
		$source = $preloaded !== null ? $preloaded['source']
			: self::currentRows(SourceTable::class, ['=ID' => $submission['SOURCE_ID']])[0];
		$resolution = json_decode($submission['RESOLUTION_SNAPSHOT'], true, 64, JSON_THROW_ON_ERROR);
		$original = json_decode($row['ORIGINAL_SUBJECT'], true, 32, JSON_THROW_ON_ERROR);
		$events = []; $revokedAt = null; $revokedBy = null;
		$eventRows = $preloaded !== null ? $preloaded['events']
			: self::currentRows(EventTable::class, ['=FACT_ID' => $row['ID']]);
		foreach ($eventRows as $event)
		{
			$date = self::immutableDate($event['EVENT_DATE']);
			$events[] = ['id' => (int)$event['ID'], 'kind' => $event['KIND'], 'actorId' => (int)$event['ACTOR_ID'],
				'date' => $date, 'serverTime' => self::immutableDate($event['SERVER_TIME']),
				'payload' => json_decode($event['PAYLOAD'], true, 64, JSON_THROW_ON_ERROR)];
			if ($event['KIND'] === 'revoke' && $revokedAt === null) { $revokedAt = $date; $revokedBy = (int)$event['ACTOR_ID']; }
		}
		$expires = $row['EXPIRES_AT'] === null ? null : self::immutableDate($row['EXPIRES_AT']);
		$status = $revokedAt !== null ? 'revoked'
			: ($expires !== null && new DateTimeImmutable() >= $expires ? 'expired' : 'accepted');

		return new Fact((int)$row['ID'], (int)$source['ID'], (int)$source['FORM_ID'],
			$metadata['sourceName'] ?? $source['NAME_SNAPSHOT'], (int)$submission['ID'], (int)$row['DOCUMENT_ID'],
			(int)$row['VERSION_ID'], $version['number'], $version['languageId'], $version['contentHash'],
			new SubjectReference(...$original), array_map(static fn(array $r): SubjectReference => new SubjectReference(...$r),
				$resolution['personBindings']), $submission['RESULT_ID'] === null ? null : (int)$submission['RESULT_ID'],
			$submission['ACTIVITY_ID'] === null ? null : (int)$submission['ACTIVITY_ID'],
			new DateTimeImmutable($frozen['presentedAt']), self::immutableDate($row['ACCEPTED_AT']),
			$row['EFFECTIVE_TEXT'], $row['EFFECTIVE_LABEL'], json_decode($row['SUBSTITUTIONS'], true, 64, JSON_THROW_ON_ERROR),
			$row['TEXT_HASH'], new ExpiryRule(...json_decode($row['RULE_SNAPSHOT'], true, 32, JSON_THROW_ON_ERROR)),
			$row['TIMEZONE'], $expires, $events, $status, $revokedAt, $revokedBy);
	}

	private static function frozen(array $row): array
	{
		$frozen = json_decode($row['MANIFEST'], true, 512, JSON_THROW_ON_ERROR);
		$metadata = $frozen['registryFreeze'] ?? null;
		if (!is_array($metadata) || array_keys($metadata) !== ['schemaVersion', 'sourceName', 'timezone', 'versions']
			|| $metadata['schemaVersion'] !== 1 || !is_string($metadata['sourceName']) || !is_array($metadata['versions'])
			|| !in_array($metadata['timezone'], DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true))
		{
			throw new \InvalidArgumentException('INVALID_PAYLOAD');
		}

		return $frozen;
	}

	public static function immutableDate(\Bitrix\Main\Type\DateTime $date): DateTimeImmutable
	{
		return (new DateTimeImmutable('@' . $date->getTimestamp()))->setTimezone(new DateTimeZone('UTC'));
	}

	public static function currentRows(string $model, array $filter): array
	{
		$query = new class($model::getEntity()) extends \Bitrix\Main\ORM\Query\Query {
			protected function query($sql)
			{
				$result = $this->entity->getConnection()->query($sql . ' FOR UPDATE');
				$result->setReplacedAliases($this->replaced_aliases);
				if ($this->isFetchModificationRequired())
				{
					$result->addFetchDataModifier([$this, 'fetchDataModificationCallback']);
				}

				return $result;
			}
		};

		return $query->setSelect(['*'])->setFilter($filter)->setOrder(['ID' => 'ASC'])->fetchAll();
	}

	public static function transaction(callable $callback): RegistryResult
	{
		$pool = Application::getInstance()->getConnectionPool(); $pool->useMasterOnly(true);
		$conn = Application::getConnection(); $owned = false;
		try
		{
			$conn->startTransaction(); $owned = true; $result = $callback(); $owned = false;
			if ($result->isSuccess()) { $conn->commitTransaction(); }
			else { self::rollback();
				$codes = array_map(static fn(Error $error): string|int => $error->getCode(), $result->getErrors());
				if (in_array('STORAGE_ERROR', $codes, true)) { self::error('STORAGE_ERROR'); }
			}

			return $result;
		}
		catch (\Throwable $e)
		{
			if ($owned) { try { self::rollback(); }
			catch (\Throwable) {} }
			if ($e instanceof \InvalidArgumentException || $e instanceof \JsonException)
			{
				return self::error('INVALID_PAYLOAD');
			}
			if ($e instanceof \UnexpectedValueException
				&& in_array($e->getMessage(), ['STALE_PRESENTATION', 'NOT_FOUND_OR_DENIED'], true))
			{
				return self::error($e->getMessage());
			}

			return self::error('STORAGE_ERROR');
		}
		finally { $pool->useMasterOnly(false); }
	}

	private static function rollback(): void
	{
		try { Application::getConnection()->rollbackTransaction(); }
		catch (\Bitrix\Main\DB\TransactionException $e)
		{
			if ($e->getMessage() !== 'Nested rollbacks are unsupported.') { throw $e; }
		}
	}

	public static function error(string $code): RegistryResult
	{
		if ($code === 'STORAGE_ERROR')
		{
			(new \Bitrix\Main\Diag\LoggerFactory())->createById('crm.legal-registry', [], false, true)
				?->error('Legal registry storage failure', ['correlationId' => bin2hex(random_bytes(16))])
			;
		}

		return (new RegistryResult())->addError(new Error('', $code));
	}
}
