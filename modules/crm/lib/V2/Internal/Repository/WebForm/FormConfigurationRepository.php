<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\WebForm;

use Bitrix\Crm\Category\Entity\Category;
use Bitrix\Crm\Integration\Bitrix24\Product;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\Settings\InvoiceSettings;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Configuration\FormConfiguration;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Section;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormChangeSet;
use Bitrix\Crm\V2\Internal\Integration\Sale\PaySystemGateway;
use Bitrix\Crm\V2\Internal\Service\WebForm\ActiveFormLimit;
use Bitrix\Crm\WebForm\Helper;
use Bitrix\Crm\WebForm\Internals\FieldTable;
use Bitrix\Crm\WebForm\Options\Dictionary;
use Bitrix\Main\Application;
use Bitrix\Main\ModuleManager;

/**
 * Builds the public subset of the CRM form dictionary from its read-only sources.
 *
 * @internal
 */
final class FormConfigurationRepository
{
	private const EXCLUDED_DYNAMIC_ENTITY_TYPE_IDS = [
		\CCrmOwnerType::SmartInvoice,
		\CCrmOwnerType::SmartDocument,
		\CCrmOwnerType::SmartB2eDocument,
	];

	/**
	 * Field properties that say which field it is instead of parametrizing it, so a client chooses
	 * nothing about them.
	 */
	private const FIELD_IDENTITY_PROPERTIES = [
		Section\Field::FIELD_NAME,
		Section\Field::FIELD_TYPE,
	];

	/** A delimiter carries no label of its own, unlike the rest of the types carrying no value. */
	private const LABELLESS_FIELD_TYPES = [
		FieldTable::TYPE_ENUM_BR,
		FieldTable::TYPE_ENUM_HR,
	];

	/**
	 * Verdicts of {@see self::isEntityAvailable()} by subject and entity type. A scheme names three
	 * or four entities and a portal publishes two schemes per smart process, so the same client
	 * entity is asked about as many times as there are schemes.
	 *
	 * @var array<int, array<int, bool>>
	 */
	private array $entityAvailability = [];

	/**
	 * Funnels the document of the running call publishes, by CRM entity type: one value for the whole
	 * snapshot, collected before the schemes are walked.
	 *
	 * @var array<int, array<int|string, array<string, mixed>>>
	 */
	private array $publishedCategories = [];

	private readonly FormReferenceRepository $references;

	private readonly PaySystemGateway $paySystems;

	/**
	 * @param null|FormReferenceRepository $references defaults to the repository the check of the
	 *        input asks the same question of: which funnel of a CRM entity type a user may work in
	 * @param null|PaySystemGateway $paySystems defaults to the gateway the check of the input asks
	 *        the same question of: which pay systems this portal has
	 */
	public function __construct(
		?FormReferenceRepository $references = null,
		?PaySystemGateway $paySystems = null,
	)
	{
		$this->references = $references ?? new FormReferenceRepository();
		$this->paySystems = $paySystems ?? new PaySystemGateway();
	}

	/**
	 * Returns the capabilities available to the specified user on this portal.
	 */
	public function get(int $userId): FormConfiguration
	{
		$dictionary = Dictionary::instance();
		$document = $dictionary->getDocument();
		$userPermissions = Container::getInstance()->getUserPermissions($userId);
		$leadEnabled = (bool)($document['lead']['enabled'] ?? false);
		$oldInvoicesEnabled = InvoiceSettings::getCurrent()->isOldInvoicesEnabled();
		$this->publishedCategories = self::getPublishedCategories($document);
		$entitySchemes = $this->getEntitySchemes(
			(array)($document['schemes'] ?? []),
			$userPermissions,
			$leadEnabled,
			$oldInvoicesEnabled,
		);
		$isCloud = Product::isCloud();

		return new FormConfiguration(
			entitySchemes: $entitySchemes,
			duplicateModes: self::getNamedCodes((array)($document['duplicateModes'] ?? [])),
			lead: ['enabled' => $leadEnabled],
			dealCategories: $this->references->getAvailableCategories(
				(array)($document['deal']['categories'] ?? []),
				\CCrmOwnerType::Deal,
				$userId,
			),
			smartProcesses: $this->getSmartProcesses(
				(array)($document['dynamic'] ?? []),
				$entitySchemes,
				$userId,
			),
			languages: self::getNamedCodes($dictionary->getLanguages()),
			currencies: self::getCurrencies($dictionary->getCurrencies()),
			fieldTypes: self::getFieldTypes(),
			// A portal with no way to ask has no pay system to offer, and the method answers with an
			// empty section instead of failing. The check of the input tells the two apart, because
			// it may not refuse an identifier it can not ask about.
			paySystems: $this->paySystems->getActivePaySystems() ?? [],
			permissions: [
				'canRead' => true,
				'canEdit' => $userPermissions->webForm()->canEdit(),
			],
			limits: ['activeForms' => self::getActiveFormsLimit()],
			product: [
				'isCloud' => $isCloud,
				'isRegionRussian' => Product::isRegionRussian(),
			],
			region: Application::getInstance()->getLicense()->getRegion() ?: 'en',
		);
	}

	/**
	 * Funnels of the dictionary of the form by CRM entity type. The document collects them for the
	 * whole portal at once, so asking it about a type costs nothing, while asking the type itself is
	 * a read of its own - one per smart process the portal has.
	 *
	 * @param array<string, mixed> $document
	 * @return array<int, array<int|string, array<string, mixed>>>
	 */
	private static function getPublishedCategories(array $document): array
	{
		$categories = [\CCrmOwnerType::Deal => (array)($document['deal']['categories'] ?? [])];
		foreach ((array)($document['dynamic'] ?? []) as $process)
		{
			$entityTypeId = (int)($process['id'] ?? 0);
			if ($entityTypeId > 0)
			{
				$categories[$entityTypeId] = (array)($process['categories'] ?? []);
			}
		}

		return $categories;
	}

	/**
	 * @param array<int|string, array<string, mixed>> $schemes
	 * @return list<array{id: int, name: string, description: string, entityNames: list<string>,
	 *     mainEntityName: ?string, hasInvoice: bool, dynamic: bool}>
	 */
	private function getEntitySchemes(
		array $schemes,
		UserPermissions $userPermissions,
		bool $leadEnabled,
		bool $oldInvoicesEnabled,
	): array
	{
		$result = [];
		foreach ($schemes as $schemeId => $scheme)
		{
			$entityNames = array_values(array_filter(
				(array)($scheme['entities'] ?? []),
				static fn (mixed $entityName): bool => is_string($entityName) && $entityName !== '',
			));
			$mainEntityTypeId = (int)($scheme['mainEntity'] ?? 0);
			$hasInvoice = (bool)($scheme['hasInvoice'] ?? false);
			if (
				$entityNames === []
				|| ($hasInvoice && !$oldInvoicesEnabled)
				|| !$this->areSchemeEntitiesAvailable(
					$entityNames,
					$mainEntityTypeId,
					$userPermissions,
					$leadEnabled,
				)
			)
			{
				continue;
			}

			$mainEntityName = $mainEntityTypeId > 0
				? \CCrmOwnerType::resolveName($mainEntityTypeId)
				: null
			;
			$result[] = [
				'id' => (int)($scheme['id'] ?? $schemeId),
				'name' => (string)($scheme['name'] ?? ''),
				'description' => (string)($scheme['description'] ?? ''),
				'entityNames' => $entityNames,
				'mainEntityName' => $mainEntityName !== '' ? $mainEntityName : null,
				'hasInvoice' => $hasInvoice,
				'dynamic' => (bool)($scheme['dynamic'] ?? false),
			];
		}

		return $result;
	}

	/**
	 * @param list<string> $entityNames
	 */
	private function areSchemeEntitiesAvailable(
		array $entityNames,
		int $mainEntityTypeId,
		UserPermissions $userPermissions,
		bool $leadEnabled,
	): bool
	{
		if (
			in_array($mainEntityTypeId, self::EXCLUDED_DYNAMIC_ENTITY_TYPE_IDS, true)
			|| ($mainEntityTypeId === \CCrmOwnerType::Lead && !$leadEnabled)
		)
		{
			return false;
		}

		foreach ($entityNames as $entityName)
		{
			$entityTypeId = (int)\CCrmOwnerType::resolveID($entityName);
			if (
				$entityTypeId <= 0
				|| in_array($entityTypeId, self::EXCLUDED_DYNAMIC_ENTITY_TYPE_IDS, true)
				|| ($entityTypeId === \CCrmOwnerType::Lead && !$leadEnabled)
				|| !$this->isEntityAvailable($entityTypeId, $userPermissions)
			)
			{
				return false;
			}
		}

		return true;
	}

	private function isEntityAvailable(int $entityTypeId, UserPermissions $userPermissions): bool
	{
		$userId = $userPermissions->getUserId();

		return $this->entityAvailability[$userId][$entityTypeId] ??= $this->readEntityAvailability(
			$entityTypeId,
			$userPermissions,
		);
	}

	/**
	 * An entity with funnels is available while the user may work in one of them, and the funnels are
	 * asked about by the rule this class publishes them by: a type available by one rule and empty of
	 * funnels by the other would offer a scheme a client cannot use.
	 */
	private function readEntityAvailability(int $entityTypeId, UserPermissions $userPermissions): bool
	{
		if ($entityTypeId === \CCrmOwnerType::Invoice)
		{
			return $userPermissions->entityType()->canReadItems($entityTypeId);
		}

		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return false;
		}

		if ($factory->isCategoriesSupported())
		{
			return $this->references->getAvailableCategories(
				$this->publishedCategories[$entityTypeId] ?? self::readCategoriesOfType($factory),
				$entityTypeId,
				$userPermissions->getUserId(),
			) !== [];
		}

		return $userPermissions->entityType()->canReadItems($entityTypeId);
	}

	/**
	 * Funnels of a type the dictionary of the form leaves out: it names the ones a submission may
	 * create its entity in, and a client entity is not one of those, though it does hold funnels of
	 * its own. There is a handful of such types on any portal, unlike the smart processes.
	 *
	 * @return list<array{id: int, name: string}>
	 */
	private static function readCategoriesOfType(Factory $factory): array
	{
		return array_map(
			static fn (Category $category): array => [
				'id' => (int)$category->getId(),
				'name' => (string)$category->getName(),
			],
			$factory->getCategories(),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $processes
	 * @param list<array{id: int, name: string, description: string, entityNames: list<string>,
	 *     mainEntityName: ?string, hasInvoice: bool, dynamic: bool}> $entitySchemes
	 * @return list<array{entityTypeId: int, entityName: string, name: string,
	 *     categories: list<array{id: int, name: string}>}>
	 */
	private function getSmartProcesses(
		array $processes,
		array $entitySchemes,
		int $userId,
	): array
	{
		$availableEntityNames = [];
		foreach ($entitySchemes as $scheme)
		{
			if ($scheme['dynamic'] && $scheme['mainEntityName'] !== null)
			{
				$availableEntityNames[$scheme['mainEntityName']] = true;
			}
		}

		$result = [];
		foreach ($processes as $process)
		{
			$entityTypeId = (int)($process['id'] ?? 0);
			$entityName = \CCrmOwnerType::resolveName($entityTypeId);
			if (
				$entityTypeId <= 0
				|| in_array($entityTypeId, self::EXCLUDED_DYNAMIC_ENTITY_TYPE_IDS, true)
				|| $entityName === ''
				|| !isset($availableEntityNames[$entityName])
				|| Container::getInstance()->getFactory($entityTypeId) === null
			)
			{
				continue;
			}

			$result[] = [
				'entityTypeId' => $entityTypeId,
				'entityName' => $entityName,
				'name' => (string)($process['name'] ?? ''),
				'categories' => $this->references->getAvailableCategories(
					(array)($process['categories'] ?? []),
					$entityTypeId,
					$userId,
				),
			];
		}

		return $result;
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @return list<array{id: string, name: string}>
	 */
	private static function getNamedCodes(array $items): array
	{
		$result = [];
		foreach ($items as $item)
		{
			$id = (string)($item['id'] ?? '');
			if ($id === '')
			{
				continue;
			}

			$result[] = [
				'id' => $id,
				'name' => (string)($item['name'] ?? ''),
			];
		}

		return $result;
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @return list<array{code: string, name: string, format: string}>
	 */
	private static function getCurrencies(array $items): array
	{
		$result = [];
		foreach ($items as $item)
		{
			$code = (string)($item['code'] ?? '');
			if ($code === '')
			{
				continue;
			}

			$result[] = [
				'code' => $code,
				'name' => (string)($item['title'] ?? ''),
				'format' => (string)($item['format'] ?? ''),
			];
		}

		return $result;
	}

	/**
	 * The types a field of this portal may be of, each with the parameters a client may choose
	 * about it. Those are the whole of what the write contract accepts for a field
	 * ({@see WebFormChangeSet::getWritableItemProperties()}) less its identity, because a dictionary
	 * promising less than `crm.form.update` takes would keep a client from what it may do. The
	 * exception is a type carrying no value of its own - the product itself names them
	 * ({@see Helper::getFieldNonValueTypes()}) - for which a placeholder or a value means nothing.
	 *
	 * @return list<array{id: string, name: string, parameters: list<string>}>
	 */
	private static function getFieldTypes(): array
	{
		$writableParameters = array_values(array_diff(
			WebFormChangeSet::getWritableItemProperties(WebForm::SECTION_FIELDS),
			self::FIELD_IDENTITY_PROPERTIES,
		));
		$typesWithoutValue = Helper::getFieldNonValueTypes();

		$result = [];
		foreach (FieldTable::getTypeList() as $id => $name)
		{
			if (
				in_array($id, Section\Field::UNSUPPORTED_TYPES, true)
				|| ($id === FieldTable::TYPE_ENUM_RESOURCEBOOKING
					&& !ModuleManager::isModuleInstalled('calendar'))
			)
			{
				continue;
			}

			$parameters = isset($typesWithoutValue[$id])
				? self::getLayoutFieldParameters((string)$id)
				: $writableParameters
			;

			$result[] = [
				'id' => (string)$id,
				'name' => (string)$name,
				'parameters' => $parameters,
			];
		}

		return $result;
	}

	/**
	 * What a type carrying no value of its own leaves of the write parameters: its label, and for a
	 * delimiter not even that.
	 *
	 * @return list<string>
	 */
	private static function getLayoutFieldParameters(string $id): array
	{
		return in_array($id, self::LABELLESS_FIELD_TYPES, true)
			? []
			: [Section\Field::FIELD_LABEL]
		;
	}

	/**
	 * The limit the portal counts working forms against, and nothing on a portal where turning a
	 * form on is not limited at all.
	 *
	 * The pair is asked of the very object the scenarios refuse by ({@see ActiveFormLimit}): what
	 * the dictionary publishes and what a creation or an activation is refused by are one count and
	 * one maximum rather than two that can drift apart.
	 *
	 * @return null|array{current: int, maximum: int}
	 */
	private static function getActiveFormsLimit(): ?array
	{
		return (new ActiveFormLimit())->describe();
	}
}
