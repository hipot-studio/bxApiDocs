<?php

namespace Bitrix\VisualEditor\Public\Adapter;

use Bitrix\Main\Event;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\InvalidOperationException;

final class AdapterRegistry
{
	private array $adapters = [];
	private bool $initialized = false;

	/**
	 * Registers an adapter without replacing existing registrations.
	 *
	 * @param string $adapterId
	 * @param EditorAdapterInterface $adapter
	 * @return void
	 */
	public function register(string $adapterId, EditorAdapterInterface $adapter): void
	{
		if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/D', $adapterId) !== 1)
		{
			throw new ArgumentException('Invalid editor adapter ID.', 'adapterId');
		}

		if (isset($this->adapters[$adapterId]))
		{
			throw new InvalidOperationException('Editor adapter ID is already registered.');
		}

		$this->adapters[$adapterId] = $adapter;
	}

	/**
	 * Resolves an adapter after collecting consumer registrations once.
	 *
	 * @param string $adapterId
	 * @return EditorAdapterInterface|null
	 */
	public function get(string $adapterId): ?EditorAdapterInterface
	{
		if (!$this->initialized)
		{
			$this->initialized = true;
			(new Event('visualeditor', 'onRegisterEditorAdapters', ['registry' => $this]))->send();
		}

		return $this->adapters[$adapterId] ?? null;
	}
}
