<?php

declare(strict_types=1);

namespace Bitrix\Mail\Grid\PasswordlessRequestsGrid\Row\Assembler;

use Bitrix\Mail\Grid\PasswordlessRequestsGrid\Row\Assembler\Field\JsFields\JsExtensionFieldAssembler;
use Bitrix\Mail\Grid\PasswordlessRequestsGrid\Settings\PasswordlessRequestsSettings;
use Bitrix\Mail\Helper\Enum\PasswordlessRequestField;
use Bitrix\Main\Grid\Row\RowAssembler;

class PasswordlessRequestsRowAssembler extends RowAssembler
{
	protected PasswordlessRequestsSettings $settings;

	/**
	 * @param list<string> $visibleColumnIds
	 */
	public function __construct(array $visibleColumnIds, PasswordlessRequestsSettings $settings)
	{
		parent::__construct($visibleColumnIds);
		$this->settings = $settings;
	}

	/**
	 * @return list<JsExtensionFieldAssembler>
	 */
	protected function prepareFieldAssemblers(): array
	{
		return [
			new Field\JsFields\EmployeeFieldAssembler([PasswordlessRequestField::Employee->value], $this->settings),
			new Field\JsFields\StatusFieldAssembler([PasswordlessRequestField::Status->value], $this->settings),
			new Field\JsFields\DateSentFieldAssembler([PasswordlessRequestField::DateSent->value], $this->settings),
		];
	}
}
