<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Currency\Grid\Row\Action;

use Bitrix\Crm\V2\Internal\Service\Currency\Compatibility\LegacyCurrencyMutationAdapter;
use Bitrix\Crm\V2\Internal\Service\Currency\CurrencyMutationService;
use Bitrix\Main\Grid\Row\Action\DataProvider;
use Bitrix\Main\Grid\Settings;

final class CurrencyDataProvider extends DataProvider
{
	private CurrencyMutationService $mutationService;
	private LegacyCurrencyMutationAdapter $mutationAdapter;

	public function __construct(
		Settings $settings,
		private readonly string $editUrlTemplate,
		private readonly string $baseCurrencyId,
		private readonly string $accountingCurrencyId,
		?CurrencyMutationService $mutationService = null,
		?LegacyCurrencyMutationAdapter $mutationAdapter = null,
		private readonly string $setBaseActionText = '',
	)
	{
		parent::__construct($settings);

		$this->mutationAdapter = $mutationAdapter ?? new LegacyCurrencyMutationAdapter();
		$this->mutationService = $mutationService ?? new CurrencyMutationService($this->mutationAdapter);
	}

	public function prepareActions(): array
	{
		return [
			new EditAction($this->editUrlTemplate),
			new SetBaseAction(
				$this->getSettings(),
				$this->baseCurrencyId,
				$this->mutationService,
				$this->mutationAdapter,
				$this->setBaseActionText,
			),
			new DeleteAction(
				$this->getSettings(),
				$this->baseCurrencyId,
				$this->accountingCurrencyId,
				$this->mutationService,
				$this->mutationAdapter,
			),
		];
	}
}
