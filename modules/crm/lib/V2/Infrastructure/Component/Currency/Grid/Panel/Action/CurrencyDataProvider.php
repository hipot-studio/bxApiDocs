<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Currency\Grid\Panel\Action;

use Bitrix\Crm\V2\Internal\Service\Currency\CurrencyMutationService;
use Bitrix\Main\Grid\Panel\Action\DataProvider;
use Bitrix\Main\Grid\Panel\Action\ForAllCheckboxAction;
use Bitrix\Main\Grid\Settings;

final class CurrencyDataProvider extends DataProvider
{
	private CurrencyMutationService $mutationService;

	public function __construct(
		Settings $settings,
		?CurrencyMutationService $mutationService = null,
	)
	{
		parent::__construct($settings);

		$this->mutationService = $mutationService ?? new CurrencyMutationService();
	}

	public function prepareActions(): array
	{
		return [
			new CurrencyEditAction($this->mutationService),
			new DeleteAction($this->getSettings()),
			new ForAllCheckboxAction(),
		];
	}
}
