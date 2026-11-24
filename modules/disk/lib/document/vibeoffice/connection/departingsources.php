<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Connection;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\Json;

final class DepartingSources
{
	public const OPTION = 'disk_vibeoffice_departing_sources';
	public const TTL = 86400;

	/** @return list<PlatformSource> */
	public function read(): array
	{
		return self::decode(Option::getRealValue('disk', self::OPTION, ''));
	}

	/** @return list<PlatformSource> */
	public function active(int $now): array
	{
		return array_values(array_filter($this->read(), static fn(PlatformSource $source): bool => $source->until > $now));
	}

	/** @return list<PlatformSource> */
	public static function decode(#[\SensitiveParameter] ?string $raw): array
	{
		if ($raw === null)
		{
			return [];
		}
		try
		{
			$entries = Json::decode($raw);
		}
		catch (ArgumentException)
		{
			throw new DepartingSourcesDamaged('The departing platform sources option is damaged.');
		}
		if (!str_starts_with(ltrim($raw), '[') || !is_array($entries) || !array_is_list($entries) || $entries === [])
		{
			throw new DepartingSourcesDamaged('The departing platform sources option is damaged.');
		}

		$sources = [];
		$keys = ['namespace', 'server', 'webhookSecret', 'policyBindingId', 'receiveMin', 'until'];
		foreach ($entries as $entry)
		{
			if (!is_array($entry) || count($entry) !== count($keys) || array_diff($keys, array_keys($entry)) !== []
				|| !is_string($entry['namespace']) || preg_match('/^[a-f0-9]{64}$/Di', $entry['namespace']) !== 1
				|| !is_string($entry['server']) || PlatformNamespace::origin($entry['server']) === null
				|| !is_string($entry['webhookSecret']) || $entry['webhookSecret'] === ''
				|| ($entry['policyBindingId'] !== null && (!is_string($entry['policyBindingId'])
					|| preg_match('/^[a-f0-9]{64}$/Di', $entry['policyBindingId']) !== 1))
				|| !in_array($entry['receiveMin'], [1, 2], true)
				|| !is_int($entry['until']) || $entry['until'] <= 0
				|| $entry['namespace'] !== PlatformNamespace::compute($entry['server'], $entry['webhookSecret']))
			{
				throw new DepartingSourcesDamaged('The departing platform sources option is damaged.');
			}
			$sources[] = new PlatformSource(
				$entry['namespace'], $entry['server'], $entry['webhookSecret'], true,
				$entry['policyBindingId'], $entry['receiveMin'], $entry['until'],
			);
		}

		return $sources;
	}

	/** @param list<PlatformSource> $sources */
	public static function encode(#[\SensitiveParameter] array $sources): string
	{
		if ($sources === [] || !array_is_list($sources))
		{
			throw new DepartingSourcesDamaged('A nonempty list of departing platform sources is required.');
		}
		$entries = [];
		foreach ($sources as $source)
		{
			$entries[] = [
				'namespace' => $source->namespace,
				'server' => $source->server,
				'webhookSecret' => $source->webhookSecret(),
				'policyBindingId' => $source->policyBindingId,
				'receiveMin' => $source->receiveMin,
				'until' => $source->until,
			];
		}

		return Json::encode($entries);
	}
}
