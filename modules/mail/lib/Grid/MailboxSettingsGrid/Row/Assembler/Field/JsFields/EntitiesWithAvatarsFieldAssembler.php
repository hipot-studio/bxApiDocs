<?php

namespace Bitrix\Mail\Grid\MailboxSettingsGrid\Row\Assembler\Field\JsFields;

use Bitrix\Main\Grid\Settings;
use Bitrix\Mail\Helper\Entity\User\User;

class EntitiesWithAvatarsFieldAssembler extends JsExtensionFieldAssembler
{
	private const EXTENSION_CLASS_NAME = 'EntitiesWithAvatarsField';

	private string $dataKey;

	public function __construct(array $columnIds, string $dataKey, Settings $settings)
	{
		parent::__construct($columnIds, $settings);
		$this->dataKey = $dataKey;
	}

	/**
	 * @return array{users: User[]}
	 */
	protected function getRenderParams(array $rawValue): array
	{
		$entitiesData = $rawValue[$this->dataKey] ?? [];

		if (empty($entitiesData))
		{
			return [
				'entities' => [],
			];
		}

		return [
			'entities' => $entitiesData,
		];
	}

	protected function getExtensionClassName(): string
	{
		return self::EXTENSION_CLASS_NAME;
	}

	protected function prepareColumnForExport(array $data): string
	{
		$entitiesData = $data[$this->dataKey] ?? [];

		if (empty($entitiesData))
		{
			return '';
		}

		$names = array_column($entitiesData, 'name');

		return implode(', ', array_filter($names));
	}
}
