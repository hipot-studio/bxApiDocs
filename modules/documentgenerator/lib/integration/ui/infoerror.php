<?php

namespace Bitrix\DocumentGenerator\Integration\UI;

use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use CMain;

final class InfoError
{
	private function __construct(
		private readonly string $message,
		private readonly ?string $description = null,
	)
	{
	}

	public static function fromResult(Result $result): ?self
	{
		$error = $result->getError();
		if ($error === null)
		{
			return null;
		}

		return new self($error->getMessage());
	}

	public function include(): void
	{
		if (Loader::includeModule('ui'))
		{
			$this->getApplication()->IncludeComponent(
				'bitrix:ui.info.error',
				'',
				[
					'TITLE' => $this->message,
					'DESCRIPTION' => $this->description ?? '',
				],
			);

			return;
		}

		ShowError($this->message);
	}

	private function getApplication(): CMain
	{
		global $APPLICATION;
		return $APPLICATION;
	}
}
