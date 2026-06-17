<?php

namespace Bitrix\Mail\Grid\MailboxSettingsGrid\Row\Assembler\Field\JsFields;

use Bitrix\Mail\Grid\MailboxSettingsGrid\Row\Assembler\Field\CustomMailboxFieldAssembler;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Web\Json;

abstract class JsExtensionFieldAssembler extends CustomMailboxFieldAssembler
{
	private const FIELD_ID_LENGTH = 6;
	private string $extensionClassName;
	private string $extensionName;

	public function __construct(array $columnIds, ?Settings $settings = null)
	{
		parent::__construct($columnIds, $settings);
		$this->extensionClassName = $this->getExtensionClassName();
		$this->extensionName = $settings?->getExtensionName();
	}

	abstract protected function getExtensionClassName(): string;
	abstract protected function getRenderParams(array $rawValue): array;

	/**
	 * @param array $rawValue Raw row data with IS_CONNECTION_REQUEST flag.
	 * @return string HTML placeholder for connection request rows.
	 */
	protected function prepareConnectionRequestPlaceholder(array $rawValue): string
	{
		return '<div class="mail__mailbox-list_mailbox-connection-request_placeholder"><i class="ui-icon-set --o-hourglass lalala"></i></div>';
	}

	protected function prepareColumn(mixed $value): mixed
	{
		if (!empty($value['IS_CONNECTION_REQUEST']) && empty($value['RENDER_AS_JS']))
		{
			return $this->prepareConnectionRequestPlaceholder($value);
		}

		if (!$this->extensionName)
		{
			return $value;
		}

		$renderParams = Json::encode($this->getRenderParams($value));
		$fieldId = Random::getString(self::FIELD_ID_LENGTH);
		$extensionParams = [
			'fieldId' => $fieldId,
			'gridId' => $this->getSettings()->getID(),
		];
		$extensionParams = Json::encode($extensionParams);

		$extension = $this->extensionName;
		$className = $this->extensionClassName;

		$script = sprintf("(new BX.%s.%s(%s)).render(%s)", $extension, $className, $extensionParams, $renderParams);

		return sprintf(
			"<div class='mailbox-grid_custom-field-container' id='%s'></div><script>%s</script>",
			$fieldId,
			$script,
		);
	}
}
