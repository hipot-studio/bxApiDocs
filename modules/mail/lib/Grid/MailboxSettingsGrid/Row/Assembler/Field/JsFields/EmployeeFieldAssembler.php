<?php

namespace Bitrix\Mail\Grid\MailboxSettingsGrid\Row\Assembler\Field\JsFields;

use Bitrix\Main\Grid\Settings;

class EmployeeFieldAssembler extends JsExtensionFieldAssembler
{
	private const EXTENSION_CLASS_NAME = 'EmployeeField';

	private string $dataKey;

	public function __construct(array $columnIds, string $dataKey, Settings $settings)
	{
		parent::__construct($columnIds, $settings);
		$this->dataKey = $dataKey;
	}

	/**
	 * @return array{
	 *     id: int,
	 *     name: string,
	 *     avatar: array{
	 *         src: string,
	 *         width: int,
	 *         height: int,
	 *         size: int,
	 *     },
	 *     pathToProfile: string,
	 * }
	 */
	protected function getRenderParams(array $rawValue): array
	{
		$userData = $rawValue[$this->dataKey] ?? [];

		if (empty($userData))
		{
			return [
				[],
			];
		}

		return [
			...$userData,
		];
	}

	/**
	 * @param array $rawValue Raw row data with OWNER_DATA for the requester.
	 * @return string HTML rendered via parent JS extension (shows requester as employee).
	 */
	protected function prepareConnectionRequestPlaceholder(array $rawValue): string
	{
		return parent::prepareColumn(array_merge($rawValue, [
			'RENDER_AS_JS' => true,
		]));
	}

	protected function getExtensionClassName(): string
	{
		return self::EXTENSION_CLASS_NAME;
	}

	protected function prepareColumnForExport(array $data): string
	{
		$userData = $data[$this->dataKey] ?? [];

		return $userData['name'] ?? '';
	}
}
