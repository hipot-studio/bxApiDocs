<?php

namespace Bitrix\Crm\Import\Builder;

use Bitrix\Crm\Import\Dto\Entity\AbstractImportSettings;
use Bitrix\Crm\Import\Dto\Entity\ImportSettings\ContactImportSettings;
use Bitrix\Crm\Import\Enum\Contact\Origin;
use Bitrix\Crm\Import\Enum\Delimiter;
use Bitrix\Crm\Import\Enum\Encoding;
use Bitrix\Main\Request;

final class ImportSettingsConfigurator
{
	public function __construct(
		private readonly AbstractImportSettings $importSettings,
	)
	{
	}

	public function configureByRequest(Request $request): self
	{
		if ($this->importSettings instanceof ContactImportSettings)
		{
			$origin = Origin::tryFrom($request->get('origin'));
			if ($origin !== null)
			{
				$this->importSettings->setOrigin($origin);
			}
		}

		if ($request->get('categoryId') !== null)
		{
			$this->importSettings->setCategoryId((int)$request->get('categoryId'));
		}

		return $this;
	}

	public function configureByImportSettingsOrigin(): self
	{
		if (!$this->importSettings instanceof ContactImportSettings)
		{
			return $this;
		}

		if ($this->importSettings->getOrigin() === Origin::Gmail)
		{
			$this->importSettings->setDelimiter(Delimiter::Comma);
			$this->importSettings->setEncoding(Encoding::UTF8);

			return $this;
		}

		if ($this->importSettings->getOrigin() === Origin::VCard)
		{
			$this->importSettings->setEncoding(Encoding::UTF8);

			return $this;
		}

		if ($this->importSettings->getOrigin() === Origin::Outlook)
		{
			$this->importSettings->setEncoding(Encoding::UTF8);
			$this->importSettings->setDelimiter(Delimiter::Comma);

			return $this;
		}

		if ($this->importSettings->getOrigin() === Origin::Yahoo)
		{
			$this->importSettings->setEncoding(Encoding::UTF8);
			$this->importSettings->setDelimiter(Delimiter::Comma);

			return $this;
		}

		return $this;
	}
}
