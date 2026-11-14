<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\LegalRegistry;

use Bitrix\Crm\V2\Internal\Access\LegalRegistry\RegistryAccess;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\DocumentTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\SourceTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\UsageTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\VersionTable;
use Bitrix\Crm\V2\Internal\Repository\LegalRegistry\UsageRepository;
use Bitrix\Crm\V2\Internal\Repository\WebForm\FormSettingsColumn;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\FormBindings;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Crm\WebForm\Internals\FormTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Type\DateTime;

/** Saves the editor projection and usage history under the same form row lock. */
final class FormBindingsService
{
	private const MAX_BINDINGS = 100;

	public static function getInstance(): self
	{
		$locator = ServiceLocator::getInstance();
		$service = $locator->has(self::class) ? $locator->get(self::class) : null;

		return $service instanceof self ? $service : new self();
	}

	public function getByForm(int $userId, int $formId): FormBindings
	{
		if ($userId <= 0 || !RegistryAccess::forUser($userId)->canReadForm() || !self::id($formId))
		{
			throw new \UnexpectedValueException('NOT_FOUND_OR_DENIED');
		}
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		$connection = Application::getConnection();
		$started = false;
		try
		{
			$connection->startTransaction();
			$started = true;
			$form = self::currentRow(FormTable::class, $formId);
			if (!$form) { throw new \UnexpectedValueException('NOT_FOUND_OR_DENIED'); }
			$source = self::currentRows(SourceTable::class, ['=FORM_ID' => $formId])[0] ?? false;
			$rows = [];
			if ($source)
			{
				$filter = ['=SOURCE_ID' => (int)$source['ID'], '=DATE_END' => null];
				$snapshot = SourceTable::getList(['filter' => ['=FORM_ID' => $formId]])->fetch();
				$rows = $snapshot && (int)$snapshot['REVISION'] === (int)$source['REVISION']
					? UsageTable::getList(['filter' => $filter])->fetchAll()
					: self::currentRows(UsageTable::class, $filter);
			}
			$bindings = $source
				? new FormBindings(self::project($rows, $form['FORM_SETTINGS'] ?? []), (int)$source['REVISION'])
				: new FormBindings([], null);
			$connection->commitTransaction();
			$started = false;

			return $bindings;
		}
		catch (\Throwable $exception)
		{
			if ($started) { self::safeRollback(); }
			throw $exception;
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	public function save(int $userId, int $formId, FormBindings $bindings): RegistryResult
	{
		if ($userId <= 0 || !self::id($formId) || !RegistryAccess::forUser($userId)->canEditForm())
		{
			return self::error('NOT_FOUND_OR_DENIED');
		}
		if (count($bindings->items) > self::MAX_BINDINGS)
		{
			return self::error('INVALID_PAYLOAD');
		}
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		$connection = Application::getConnection();
		$started = false;
		$guard = null;
		try
		{
			$guard = FormMutationGuard::acquire($formId);
			$connection->startTransaction();
			$started = true;
			$form = self::currentRow(FormTable::class, $formId);
			$result = $form ? $this->saveLocked($form, $bindings) : self::error('NOT_FOUND_OR_DENIED');
			if (!$result->isSuccess())
			{
				$started = false;
				self::rollback();

				return $result;
			}
			$started = false;
			$connection->commitTransaction();

			return $result;
		}
		catch (DuplicateEntryException)
		{
			if ($started) { self::safeRollback(); }

			return self::error('REVISION_CONFLICT');
		}
		catch (\Throwable $error)
		{
			if ($started) { self::safeRollback(); }

			return self::error($error->getMessage() === 'REVISION_CONFLICT' ? 'REVISION_CONFLICT' : 'STORAGE_ERROR');
		}
		finally
		{
			try { $guard?->release(); }
			finally { $pool->useMasterOnly(false); }
		}
	}

	private function saveLocked(array $form, FormBindings $bindings): RegistryResult
	{
		$formId = (int)$form['ID'];
		$sourceSnapshot = SourceTable::getList(['filter' => ['=FORM_ID' => $formId]])->fetch();
		$source = $bindings->expectedSourceRevision === null
			? ($sourceSnapshot ? self::currentRow(SourceTable::class, (int)$sourceSnapshot['ID']) : false)
			: (self::currentRows(SourceTable::class, ['=FORM_ID' => $formId])[0] ?? false);
		$expected = $bindings->expectedSourceRevision;
		if (($source === false && $expected !== null)
			|| ($source !== false && ($expected === null || (int)$source['REVISION'] !== $expected)))
		{
			return self::error('REVISION_CONFLICT');
		}
		if ($source && $source['STATE'] !== 'active') { return self::error('INVALID_STATE'); }
		if ($source && !$sourceSnapshot) { return self::error('REVISION_CONFLICT'); }
		if ($source && $sourceSnapshot && (int)$sourceSnapshot['REVISION'] !== (int)$source['REVISION'])
		{
			return self::error('REVISION_CONFLICT');
		}

		$settings = is_array($form['FORM_SETTINGS'] ?? null) ? $form['FORM_SETTINGS'] : [];
		$hadStored = array_key_exists('LEGAL_DOCUMENTS', $settings);
		$stored = $settings['LEGAL_DOCUMENTS'] ?? null;
		$settings['LEGAL_DOCUMENTS'] = $bindings->items;
		if (FormSettingsColumn::storedSize($settings) > FormSettingsColumn::MAX_STORED_BYTES)
		{
			return self::error('INVALID_PAYLOAD');
		}
		if (!$source && $bindings->items === [])
		{
			if ($hadStored)
			{
				unset($settings['LEGAL_DOCUMENTS']);
				$updated = FormTable::update($formId, ['FORM_SETTINGS' => $settings]);
				if (!$updated->isSuccess()) { return self::error('STORAGE_ERROR'); }
			}

			return (new RegistryResult())->setRevision(null);
		}
		$newSource = !$source;
		$rows = $newSource ? [] : UsageTable::getList(['filter' => [
			'=SOURCE_ID' => (int)$source['ID'], '=DATE_END' => null,
		]])->fetchAll();
		$current = [];
		foreach ($rows as $row) { $current[(int)$row['DOCUMENT_ID']] = $row; }
		$desired = [];
		foreach ($bindings->items as $item) { $desired[$item['documentId']] = $item; }
		$preflight = [];
		foreach ($desired as $documentId => $item)
		{
			$row = $current[$documentId] ?? null;
			if ($row && (int)$row['VERSION_ID'] === $item['versionId']) { continue; }
			$preflight[$documentId] = $item['versionId'];
		}
		if (!self::validNewVersions($preflight))
		{
			return self::error('INVALID_STATE');
		}
		if ($bindings->items !== [] && !(new FormPresentationService())->fitsBindings($formId, $bindings->items))
		{
			return self::error('INVALID_PAYLOAD');
		}
		if (!$source)
		{
			$created = SourceTable::add(['FORM_ID' => $formId, 'NAME_SNAPSHOT' => (string)$form['NAME'],
				'STATE' => 'active', 'REVISION' => 1]);
			if (!$created->isSuccess()) { return self::error('STORAGE_ERROR'); }
			$source = self::currentRow(SourceTable::class, (int)$created->getId());
		}
		if (!$source) { return self::error('STORAGE_ERROR'); }

		$usage = new UsageRepository();
		$revision = (int)$source['REVISION'];
		$usageChanged = false;
		foreach ($current as $documentId => $row)
		{
			if (isset($desired[$documentId])) { continue; }
			$result = $usage->changeUsage($formId, 'unbind', $documentId, null, false, false, $revision);
			if (!$result->isSuccess()) { return $result; }
			$revision++;
			$usageChanged = true;
		}
		foreach ($bindings->items as $item)
		{
			$documentId = $item['documentId'];
			$row = $current[$documentId] ?? null;
			if ($row && (int)$row['VERSION_ID'] === $item['versionId']
				&& ($row['CHECKED'] === 'Y') === $item['checked']
				&& ($row['REQUIRED'] === 'Y') === $item['required'])
			{
				continue;
			}
			if ($row && (int)$row['VERSION_ID'] === $item['versionId'])
			{
				$result = self::replaceBehavior($source, $row, $item, $revision, (string)$form['NAME']);
			}
			else
			{
					$result = $row
						? $usage->changeUsage($formId, 'replace', $documentId, $item['versionId'],
							$item['checked'], $item['required'], $revision, (string)$form['NAME'])
						: self::bindNew($source, $item, $revision, (string)$form['NAME']);
			}
			if (!$result->isSuccess()) { return $result; }
			$revision++;
			$usageChanged = true;
		}
		if (!$usageChanged && $stored !== $bindings->items)
		{
			if ($revision >= 2147483647) { return self::error('INVALID_STATE'); }
			$updated = SourceTable::update((int)$source['ID'], ['REVISION' => $revision + 1]);
			if (!$updated->isSuccess()) { return self::error('STORAGE_ERROR'); }
			$revision++;
		}
		if ($stored !== $bindings->items)
		{
			$updated = FormTable::update($formId, ['FORM_SETTINGS' => $settings]);
			if (!$updated->isSuccess()) { return self::error('STORAGE_ERROR'); }
		}
		return (new RegistryResult())->setRevision($revision);
	}

	private static function replaceBehavior(array $source, array $row, array $item,
		int $revision, string $formName): RegistryResult
	{
		$now = new DateTime();
		$closed = UsageTable::update((int)$row['ID'], ['DATE_END' => $now]);
		if (!$closed->isSuccess()) { return self::error('STORAGE_ERROR'); }
		$added = UsageTable::add(['SOURCE_ID' => (int)$source['ID'], 'DOCUMENT_ID' => $item['documentId'],
			'VERSION_ID' => $item['versionId'], 'REVISION' => $revision + 1,
			'CHECKED' => $item['checked'] ? 'Y' : 'N', 'REQUIRED' => $item['required'] ? 'Y' : 'N',
			'DATE_START' => $now, 'FORM_NAME_SNAPSHOT' => $formName]);
		if (!$added->isSuccess()) { return self::error('STORAGE_ERROR'); }
		$updated = SourceTable::update((int)$source['ID'], ['REVISION' => $revision + 1,
			'NAME_SNAPSHOT' => $formName]);

		return $updated->isSuccess() ? new RegistryResult() : self::error('STORAGE_ERROR');
	}

	private static function bindNew(array $source, array $item, int $revision, string $formName): RegistryResult
	{
		$added = UsageTable::add(['SOURCE_ID' => (int)$source['ID'], 'DOCUMENT_ID' => $item['documentId'],
			'VERSION_ID' => $item['versionId'], 'REVISION' => $revision + 1,
			'CHECKED' => $item['checked'] ? 'Y' : 'N', 'REQUIRED' => $item['required'] ? 'Y' : 'N',
			'DATE_START' => new DateTime(), 'FORM_NAME_SNAPSHOT' => $formName]);
		if (!$added->isSuccess()) { return self::error('STORAGE_ERROR'); }
		$updated = SourceTable::update((int)$source['ID'], ['REVISION' => $revision + 1,
			'NAME_SNAPSHOT' => $formName]);

		return $updated->isSuccess() ? new RegistryResult() : self::error('STORAGE_ERROR');
	}

	private static function validNewVersions(array $bindings): bool
	{
		if ($bindings === []) { return true; }
		$documentIds = array_keys($bindings);
		sort($documentIds, SORT_NUMERIC);
		$versionIds = array_values(array_unique($bindings));
		sort($versionIds, SORT_NUMERIC);
		$documents = array_column(self::currentRows(DocumentTable::class, ['@ID' => $documentIds],
			['ID', 'ARCHIVED']), null, 'ID');
		$versions = array_column(self::currentRows(VersionTable::class, ['@ID' => $versionIds],
			['ID', 'DOCUMENT_ID', 'STATE', 'VALID_FROM', 'VALID_TO']), null, 'ID');
		$now = time();
		foreach ($bindings as $documentId => $versionId)
		{
			$document = $documents[$documentId] ?? null;
			$version = $versions[$versionId] ?? null;
			if (!$document || !$version || $document['ARCHIVED'] === 'Y'
				|| (int)$version['DOCUMENT_ID'] !== $documentId || $version['STATE'] !== 'published'
				|| ($version['VALID_FROM'] !== null && $version['VALID_FROM']->getTimestamp() > $now)
				|| ($version['VALID_TO'] !== null && $version['VALID_TO']->getTimestamp() < $now))
			{
				return false;
			}
		}

		return true;
	}

	private static function project(array $rows, mixed $settings): array
	{
		$stored = is_array($settings) && is_array($settings['LEGAL_DOCUMENTS'] ?? null)
			? $settings['LEGAL_DOCUMENTS'] : [];
		$presentation = [];
		foreach ($stored as $item)
		{
			if (is_array($item) && self::id($item['documentId'] ?? null))
			{
				$presentation[$item['documentId']] = $item;
			}
		}
		$items = [];
		foreach ($rows as $row)
		{
			$documentId = (int)$row['DOCUMENT_ID'];
			$item = $presentation[$documentId] ?? [];
			$items[] = ['documentId' => $documentId, 'versionId' => (int)$row['VERSION_ID'],
				'checked' => $row['CHECKED'] === 'Y', 'required' => $row['REQUIRED'] === 'Y',
				'order' => is_int($item['order'] ?? null) ? $item['order'] : PHP_INT_MAX,
				'notice' => is_string($item['notice'] ?? null) ? $item['notice'] : ''];
		}
		usort($items, static fn(array $left, array $right): int =>
			[$left['order'], $left['documentId']] <=> [$right['order'], $right['documentId']]);
		foreach ($items as $position => &$item) { $item['order'] = $position; }
		unset($item);

		return $items;
	}

	private static function currentRow(string $model, int $id): array|false
	{
		return self::currentRows($model, ['=ID' => $id])[0] ?? false;
	}

	private static function currentRows(string $model, array $filter, array $select = ['*']): array
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

		return $query->setSelect($select)->setFilter($filter)->setOrder(['ID' => 'ASC'])->fetchAll();
	}

	private static function id(mixed $id): bool
	{
		return is_int($id) && $id > 0 && $id <= 2147483647;
	}

	private static function error(string $code): RegistryResult
	{
		return (new RegistryResult())->addError(new Error($code, $code));
	}

	private static function rollback(): void
	{
		try { Application::getConnection()->rollbackTransaction(); }
		catch (TransactionException $exception)
		{
			if ($exception->getMessage() !== 'Nested rollbacks are unsupported.') { throw $exception; }
		}
	}

	private static function safeRollback(): void
	{
		try { self::rollback(); }
		catch (\Throwable) { /* Preserve the safe registry error. */ }
	}
}
