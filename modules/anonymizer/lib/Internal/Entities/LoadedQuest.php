<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Entities;

use Bitrix\Anonymizer\Internal\Exceptions\AnonymizerException;
use Bitrix\Anonymizer\Internal\Models\QuestTable;
use Bitrix\Anonymizer\Public\Providers\ProviderInterface;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;

/**
 * Quest loaded from DB by id. Created only via Quest::getById() (which delegates here); id always matches the loaded row.
 * Consumers use Quest::getById(); LoadedQuest extends Quest and can be substituted.
 */
class LoadedQuest extends Quest
{
	protected const SAVE_IN_CONSTRUCTOR = false;

	/**
	 * Constructor is private: use getById() only. Id is set from the loaded row, so it always matches provider/handler.
	 *
	 * @param ProviderInterface $provider Provider that supplies data for anonymization.
	 * @param string $handlerClass Handler class that processes anonymization events.
	 * @param string $forModule Calling module.
	 * @param int $id Quest record ID from DB.
	 * @throws AnonymizerException
	 */
	private function __construct(ProviderInterface $provider, string $handlerClass, string $forModule, int $id)
	{
		parent::__construct($provider, $handlerClass, $forModule);
		$this->id = $id;
		$this->context->questId = $id;
	}

	/**
	 * Load quest from DB by ID. Returns null if not found or provider cannot be restored.
	 * Handler class is taken only from the record (security).
	 */
	public static function getById(int $id): ?self
	{
		$row = QuestTable::query()
			->setSelect(['ID', 'PROVIDER_CLASS', 'PROVIDER_DATA', 'HANDLER_CLASS', 'MODULE_ID'])
			->where('ID', $id)
			->fetch()
		;

		if (
			!$row
			|| empty($row['PROVIDER_CLASS'])
			|| empty($row['HANDLER_CLASS'])
			|| empty($row['MODULE_ID'])
		)
		{
			return null;
		}

		try
		{
			Loader::includeModule($row['MODULE_ID']);
		}
		catch (LoaderException $e)
		{
			return null;
		}

		$providerClass = $row['PROVIDER_CLASS'];
		$data = $row['PROVIDER_DATA'];

		if (!class_exists($providerClass) || !is_subclass_of($providerClass, ProviderInterface::class))
		{
			return null;
		}

		$provider = $providerClass::createFromData($data);
		if ($provider === null)
		{
			return null;
		}

		try
		{
			return new self($provider, $row['HANDLER_CLASS'], $row['MODULE_ID'], (int)$row['ID']);
		}
		catch (AnonymizerException $e)
		{
			return null;
		}
	}
}
