<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateReverser;

/**
 * Index over the flat Links list produced by LinkBuilder.
 *
 * Links format: [["fromName:fromPort", "toName:toPort"], ...]
 */
final class LinkGraph
{
	/** @var array<string, array<string, array<int, array{name: string, port: string}>>> */
	private array $outgoing = [];

	/** @var array<string, array<string, array<int, array{name: string, port: string}>>> */
	private array $incoming = [];

	/**
	 * @param list<array> $links raw Links from template.json
	 * @param array<string, true>|null $knownNodes if provided, unknown nodes are collapsed transitively
	 */
	public function __construct(array $links, ?array $knownNodes = null)
	{
		foreach ($links as $link)
		{
			if (!is_array($link) || !isset($link[0], $link[1]))
			{
				continue;
			}

			[$fromName, $fromPort] = self::split((string)$link[0]);
			[$toName, $toPort] = self::split((string)$link[1]);

			$this->outgoing[$fromName][$fromPort][] = ['name' => $toName, 'port' => $toPort];
			$this->incoming[$toName][$toPort][] = ['name' => $fromName, 'port' => $fromPort];
		}

		if ($knownNodes !== null)
		{
			$this->collapseUnknown($knownNodes);
		}
	}

	/**
	 * Removes nodes not present in $known by routing each (src → unknown → dst) chain
	 * into a direct (src → dst) edge. Chains of consecutive unknowns work because we
	 * route to whatever the unknown currently points to — including other unknowns —
	 * and process every unknown in the same pass.
	 */
	private function collapseUnknown(array $known): void
	{
		$unknowns = [];
		foreach (array_keys($this->outgoing + $this->incoming) as $name)
		{
			if (!isset($known[$name]))
			{
				$unknowns[] = $name;
			}
		}

		foreach ($unknowns as $name)
		{
			$inAll = $this->incoming[$name] ?? [];
			$outAll = $this->outgoing[$name] ?? [];

			$flatIn = $inAll ? array_merge(...array_values($inAll)) : [];
			$flatOut = $outAll ? array_merge(...array_values($outAll)) : [];

			foreach ($flatIn as $from)
			{
				foreach ($flatOut as $to)
				{
					$this->outgoing[$from['name']][$from['port']][] = $to;
					$this->incoming[$to['name']][$to['port']][] = $from;
				}
			}

			foreach ($flatIn as $from)
			{
				$this->outgoing[$from['name']] = self::removeEdgesPointingTo($this->outgoing[$from['name']] ?? [], $name);
			}
			foreach ($flatOut as $to)
			{
				$this->incoming[$to['name']] = self::removeEdgesPointingTo($this->incoming[$to['name']] ?? [], $name);
			}
			unset($this->outgoing[$name], $this->incoming[$name]);
		}

		$this->deduplicate();
	}

	private function deduplicate(): void
	{
		$this->outgoing = self::dedupePortMaps($this->outgoing);
		$this->incoming = self::dedupePortMaps($this->incoming);
	}

	/**
	 * Removes all edges in $portMap whose endpoint references the given node name.
	 *
	 * @param array<string, list<array{name: string, port: string}>> $portMap port-id → list of endpoints
	 * @return array<string, list<array{name: string, port: string}>>
	 */
	private static function removeEdgesPointingTo(array $portMap, string $name): array
	{
		foreach ($portMap as $port => $edges)
		{
			$portMap[$port] = array_values(array_filter(
				$edges,
				static fn($e) => $e['name'] !== $name,
			));
		}

		return $portMap;
	}

	/**
	 * @param array<string, array<string, list<array{name: string, port: string}>>> $maps
	 * @return array<string, array<string, list<array{name: string, port: string}>>>
	 */
	private static function dedupePortMaps(array $maps): array
	{
		foreach ($maps as $name => $portMap)
		{
			foreach ($portMap as $port => $edges)
			{
				$seen = [];
				$unique = [];
				foreach ($edges as $e)
				{
					$key = $e['name'] . ':' . $e['port'];
					if (!isset($seen[$key]))
					{
						$seen[$key] = true;
						$unique[] = $e;
					}
				}
				$maps[$name][$port] = $unique;
			}
		}

		return $maps;
	}

	public function follow(string $name, string $port): ?string
	{
		$targets = $this->outgoing[$name][$port] ?? [];

		return $targets[0]['name'] ?? null;
	}

	/** @return list<array{name: string, port: string}> */
	public function allOutgoing(string $name, string $port): array
	{
		return $this->outgoing[$name][$port] ?? [];
	}

	public function inDegree(string $name, string $port): int
	{
		return count($this->incoming[$name][$port] ?? []);
	}

	/**
	 * @return list<string> outgoing port ids sorted numerically by their index
	 *                     (so "o2" comes before "o10" — lexicographic sort would break that)
	 */
	public function outgoingPorts(string $name): array
	{
		$ports = [];
		foreach ($this->outgoing[$name] ?? [] as $port => $targets)
		{
			if (!empty($targets))
			{
				$ports[] = $port;
			}
		}
		usort($ports, static function (string $a, string $b): int {
			return ((int)substr($a, 1)) <=> ((int)substr($b, 1));
		});

		return $ports;
	}

	/** @return array{0: string, 1: string} */
	private static function split(string $endpoint): array
	{
		$parts = explode(':', $endpoint, 2);

		return [$parts[0] ?? '', $parts[1] ?? ''];
	}
}
