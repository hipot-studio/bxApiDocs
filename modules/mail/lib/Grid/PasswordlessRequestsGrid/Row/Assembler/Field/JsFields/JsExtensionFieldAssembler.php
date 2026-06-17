<?php

declare(strict_types=1);

namespace Bitrix\Mail\Grid\PasswordlessRequestsGrid\Row\Assembler\Field\JsFields;

use Bitrix\Mail\Grid\PasswordlessRequestsGrid\Row\Assembler\Field\CustomPasswordlessFieldAssembler;
use Bitrix\Mail\Grid\PasswordlessRequestsGrid\Settings\PasswordlessRequestsSettings;
use Bitrix\Mail\Helper\Dto\PasswordlessRequestsGrid\PasswordlessRequestRowDto;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Web\Json;

abstract class JsExtensionFieldAssembler extends CustomPasswordlessFieldAssembler
{
	private const FIELD_ID_LENGTH = 6;
	private string $extensionClassName;
	private string $extensionName;

	/**
	 * @param string[] $columnIds
	 */
	public function __construct(array $columnIds, PasswordlessRequestsSettings $settings)
	{
		parent::__construct($columnIds, $settings);
		$this->extensionClassName = $this->getExtensionClassName();
		$this->extensionName = $settings->getExtensionName();
	}

	abstract protected function getExtensionClassName(): string;

	/**
	 * @return array<string, mixed>
	 */
	abstract protected function getRenderParams(PasswordlessRequestRowDto $dto): array;

	protected function prepareColumn(mixed $value): mixed
	{
		if (!$this->extensionName)
		{
			return $value;
		}

		$dto = PasswordlessRequestRowDto::fromGridRow($value);
		$renderParams = Json::encode($this->getRenderParams($dto));
		$fieldId = Random::getString(self::FIELD_ID_LENGTH);
		$extensionParams = Json::encode([
			'fieldId' => $fieldId,
			'gridId' => $this->getSettings()->getID(),
		]);

		$script = sprintf(
			"(new BX.%s.%s(%s)).render(%s)",
			$this->extensionName,
			$this->extensionClassName,
			$extensionParams,
			$renderParams,
		);

		return sprintf(
			"<div class='passwordless-grid_custom-field-container' id='%s'></div><script>%s</script>",
			$fieldId,
			$script,
		);
	}
}
