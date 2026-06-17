<?php

declare(strict_types=1);

namespace Bitrix\Mail\Grid\PasswordlessRequestsGrid\Filter;

use Bitrix\Mail\Helper\Enum\PasswordlessRequestField;
use Bitrix\Main\Filter\DataProvider;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\UI\Filter\Options;

class PasswordlessRequestsFilter extends Filter
{
	private Options $filterOptions;

	protected $uiFilterServiceFields = [
		PasswordlessRequestField::Employee->value,
		PasswordlessRequestField::Email->value,
		PasswordlessRequestField::Status->value,
		PasswordlessRequestField::DateSent->value,
	];

	public function __construct(
		string $ID,
		DataProvider $entityDataProvider,
		?array $extraDataProviders = null,
		?array $params = null,
	)
	{
		parent::__construct($ID, $entityDataProvider, $extraDataProviders, $params);

		$this->filterOptions = new Options($this->getId());
	}

	public function getFilterPresets(): array
	{
		return array_merge(
			$this->filterOptions->getPresets(),
			$this->filterOptions->getDefaultPresets(),
		);
	}
}
