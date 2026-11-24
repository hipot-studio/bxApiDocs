<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Repository\UnifiedLinkAccessRepository;
use Bitrix\Disk\Internals\ObjectOptionsTable;
use Bitrix\Main\Application;
use Bitrix\Main\Web\Json;

final class UnifiedLinkSourceRevision
{
	public const OPTION = 'vo-unified-rev';

	/** The caller owns the transaction and has locked the existing object and its access row. */
	public static function advanceLocked(int $objectId): void
	{
		if (!DocumentStateRepository::hasOpenTransaction())
		{
			throw new \LogicException('Unified link revisions require an object transaction.');
		}
		$connection = Application::getConnection();
		if (!Feature::isSharedEditingEnabled() && !SharedEditingUsage::isPersistedFresh())
		{
			return;
		}
		$helper = $connection->getSqlHelper();
		$connection->queryExecute('INSERT INTO ' . ObjectOptionsTable::getTableName()
			. ' (' . $helper->quote('OBJECT_ID') . ', ' . $helper->quote('NAME') . ', ' . $helper->quote('VALUE') . ')'
			. ' VALUES (' . $objectId . ", '" . self::OPTION . "', '0')"
			. ($connection->getType() === 'pgsql' ? ' ON CONFLICT (' . $helper->quote('OBJECT_ID') . ', ' . $helper->quote('NAME') . ') DO NOTHING' : ' ON DUPLICATE KEY UPDATE ID = ID'));
		$inserted = (int)$connection->getAffectedRowsCount() === 1;
		$row = DocumentStateRepository::fetchForUpdate(ObjectOptionsTable::query()->setSelect(['ID', 'VALUE'])
			->where('OBJECT_ID', $objectId)->where('NAME', self::OPTION));
		if (!$row) { throw new \RuntimeException('The unified link revision row is unavailable.'); }
		// Only this transaction's initial marker may be zero; a previously stored zero is corrupt.
		$revision = $inserted && $row['VALUE'] === '0' ? 0 : self::parseRevision((string)$row['VALUE']);
		if ($revision === PHP_INT_MAX)
		{
			throw new \OverflowException('Unified link revision exhausted.');
		}
		$result = ObjectOptionsTable::update($row['ID'], ['VALUE' => (string)($revision + 1)]);
		if (!$result->isSuccess())
		{
			throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
		}
	}

	public static function fingerprint(File $file, bool $bypassOuterRepeatableRead = false): string
	{
		$id = (int)$file->getRealObjectId();
		return self::fingerprintsByObjectIds([$id], $bypassOuterRepeatableRead)[$id];
	}

	/** @return array<int, string> */
	public static function fingerprintsByObjectIds(array $objectIds, bool $bypassOuterRepeatableRead = false): array
	{
		$bypassOuterRepeatableRead = $bypassOuterRepeatableRead && DocumentStateRepository::hasOpenTransaction()
			&& !DocumentStateRepository::hasFreshDocumentTransaction();
		$snapshot = UnifiedLinkAccessRepository::sourceSnapshot($objectIds, $bypassOuterRepeatableRead);
		$ids = $snapshot['ids'] ?? array_keys($snapshot['levels']);
		if (!$ids)
		{
			$result = [];
			foreach ($snapshot['paths'] as $objectId => $path)
			{
				$result[$objectId] = hash('sha256', 'unified-link:' . $objectId);
			}
			return $result;
		}
		$query = ObjectOptionsTable::query()->setSelect(['OBJECT_ID', 'VALUE'])->whereIn('OBJECT_ID', $ids)
			->where('NAME', self::OPTION)->setOrder(['OBJECT_ID' => 'ASC']);
		$rows = $bypassOuterRepeatableRead ? DocumentStateRepository::fetchAllForShare($query) : $query->fetchAll();
		$revisions = [];
		foreach ($rows as $row)
		{
			self::parseRevision((string)$row['VALUE']);
			$revisions[(int)$row['OBJECT_ID']] = (string)$row['VALUE'];
		}
		$result = [];
		foreach ($snapshot['paths'] as $objectId => $path)
		{
			$values = [];
			foreach ($path as $id)
			{
				if (isset($revisions[$id])) { $values[] = [$id, $revisions[$id]]; }
			}
			$result[$objectId] = hash('sha256', 'unified-link:' . $objectId . ($values ? Json::encode($values) : ''));
		}
		return $result;
	}

	private static function parseRevision(string $value): int
	{
		$maximum = (string)PHP_INT_MAX;
		if (!preg_match('/^[1-9][0-9]*$/D', $value) || strlen($value) > strlen($maximum)
			|| (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0))
		{
			throw new \UnexpectedValueException('Invalid unified link revision.');
		}
		return (int)$value;
	}
}
