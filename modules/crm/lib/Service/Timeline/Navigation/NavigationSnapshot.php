<?php

namespace Bitrix\Crm\Service\Timeline\Navigation;

final class NavigationSnapshot
{
	public const SCHEMA_VERSION = 2;
	public const POLICY_VERSION = 1;

	public function __construct(
		public readonly string $viewId,
		public readonly string $scopeId,
		public readonly array $owner,
		public readonly array $native,
		public readonly array $crm,
	)
	{
	}

	public function toArray(): array
	{
		$native = $this->native;
		$crm = $this->crm;
		unset($crm['staleTypeKeys']);
		if ($native['fields'] === [])
		{
			$native['fields'] = new \stdClass();
		}
		if ($crm['typeIdentities'] === [])
		{
			$crm['typeIdentities'] = new \stdClass();
		}

		return [
			'schemaVersion' => self::SCHEMA_VERSION,
			'viewId' => $this->viewId,
			'scopeId' => $this->scopeId,
			'owner' => $this->owner,
			'native' => $native,
			'crm' => $crm,
		];
	}
}
