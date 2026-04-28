<?php

namespace Bitrix\Crm\Import\ImportEntities\Contact;

use Bitrix\Crm\Address\Enum\FieldName;
use Bitrix\Crm\EntityAddressType;
use Bitrix\Crm\Import\Collection\FieldCollection;
use Bitrix\Crm\Import\Contract\ImportEntityInterface;
use Bitrix\Crm\Import\Contract\Strategy\FieldBindingMapperInterface;
use Bitrix\Crm\Import\Dto\Entity\AbstractImportSettings;
use Bitrix\Crm\Import\Dto\Entity\ImportSettings\ContactImportSettings;
use Bitrix\Crm\Import\ImportEntityFields\Address;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Birthdate;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Comments;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CompanyId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\LastName;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Name;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Post;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\SecondName;
use Bitrix\Crm\Import\ImportEntityFields\IndexedMultifield;
use Bitrix\Crm\Import\Strategy\FieldBindingMapper\ByRegexp;
use Bitrix\Crm\Multifield\Type\Email;
use Bitrix\Crm\Multifield\Type\Phone;
use Bitrix\Crm\Multifield\Type\Web;
use CCrmOwnerType;

final class YahooContact implements ImportEntityInterface
{
	private ?FieldCollection $fields = null;

	public function __construct(
		private readonly ContactImportSettings $importSettings,
	)
	{
	}

	public function getFields(): FieldCollection
	{
		if ($this->fields !== null)
		{
			return $this->fields;
		}

		$this->fields = new FieldCollection([
			new Name(CCrmOwnerType::Contact),
			new SecondName(CCrmOwnerType::Contact),
			new LastName(CCrmOwnerType::Contact),
			new CompanyId(CCrmOwnerType::Contact),
			new Post(CCrmOwnerType::Contact),

			// Email
			new IndexedMultifield(
				index: 1,
				id: Email::ID,
				type: Email::VALUE_TYPE_OTHER,
			),

			// Home Email
			new IndexedMultifield(
				index: 1,
				id: Email::ID,
				type: Email::VALUE_TYPE_HOME,
			),

			// Work Email
			new IndexedMultifield(
				index: 1,
				id: Email::ID,
				type: Email::VALUE_TYPE_WORK,
			),

			// Phone
			new IndexedMultifield(
				index: 1,
				id: Phone::ID,
				type: Phone::VALUE_TYPE_OTHER,
			),

			// Home
			new IndexedMultifield(
				index: 1,
				id: Phone::ID,
				type: Phone::VALUE_TYPE_HOME,
			),

			// Work
			new IndexedMultifield(
				index: 1,
				id: Phone::ID,
				type: Phone::VALUE_TYPE_WORK,
			),

			// Pager
			new IndexedMultifield(
				index: 1,
				id: Phone::ID,
				type: Phone::VALUE_TYPE_PAGER,
			),

			// Fax
			new IndexedMultifield(
				index: 1,
				id: Phone::ID,
				type: Phone::VALUE_TYPE_FAX,
			),

			// Mobile
			new IndexedMultifield(
				index: 1,
				id: Phone::ID,
				type: Phone::VALUE_TYPE_MOBILE,
			),

			// Other
			new IndexedMultifield(
				index: 1,
				id: Phone::ID,
				type: Phone::VALUE_TYPE_OTHER,
			),

			// Work Address
			new Address(type: EntityAddressType::Work, id: FieldName::ADDRESS_1),
			new Address(type: EntityAddressType::Work, id: FieldName::CITY),
			new Address(type: EntityAddressType::Work, id: FieldName::REGION),
			new Address(type: EntityAddressType::Work, id: FieldName::POSTAL_CODE),
			new Address(type: EntityAddressType::Work, id: FieldName::COUNTRY),

			// Home Address
			new Address(type: EntityAddressType::Home, id: FieldName::ADDRESS_1),
			new Address(type: EntityAddressType::Home, id: FieldName::CITY),
			new Address(type: EntityAddressType::Home, id: FieldName::REGION),
			new Address(type: EntityAddressType::Home, id: FieldName::POSTAL_CODE),
			new Address(type: EntityAddressType::Home, id: FieldName::COUNTRY),

			// Other Address
			new Address(type: EntityAddressType::Primary, id: FieldName::ADDRESS_1),
			new Address(type: EntityAddressType::Primary, id: FieldName::CITY),
			new Address(type: EntityAddressType::Primary, id: FieldName::REGION),
			new Address(type: EntityAddressType::Primary, id: FieldName::POSTAL_CODE),
			new Address(type: EntityAddressType::Primary, id: FieldName::COUNTRY),

			new Birthdate(CCrmOwnerType::Contact),
			new Comments(CCrmOwnerType::Contact),

			// website
			new IndexedMultifield(
				index: 1,
				id: Web::ID,
				type: Web::VALUE_TYPE_OTHER,
			),
		]);

		return $this->fields;
	}

	public function getSettings(): AbstractImportSettings
	{
		return $this->importSettings;
	}

	public function getFieldBindingMapper(): FieldBindingMapperInterface
	{
		$rules = [
			(new ByRegexp\Rule())
				->addRegexp('/^First Name$/i')
				->setCreator(static fn () => Name::ID),

			(new ByRegexp\Rule())
				->addRegexp('/^Middle Name$/i')
				->setCreator(static fn () => SecondName::ID),

			(new ByRegexp\Rule())
				->addRegexp('/^Last Name$/i')
				->setCreator(static fn () => LastName::ID),

			(new ByRegexp\Rule())
				->addRegexp('/^Company$/i')
				->setCreator(static fn () => CompanyId::ID),

			(new ByRegexp\Rule())
				->addRegexp('/^Job Title$/i')
				->setCreator(static fn () => Post::ID),

			(new ByRegexp\Rule())
				->addRegexp('/^Email$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 1,
						id: Email::ID,
						type: Email::VALUE_TYPE_OTHER,
					),
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Home Email$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 1,
						id: Email::ID,
						type: Email::VALUE_TYPE_HOME,
					),
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Work Email$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 1,
						id: Email::ID,
						type: Email::VALUE_TYPE_WORK,
					),
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Phone$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 1,
						id: Phone::ID,
						type: Phone::VALUE_TYPE_OTHER,
					),
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Home$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 1,
						id: Phone::ID,
						type: Phone::VALUE_TYPE_HOME,
					),
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Work$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 1,
						id: Phone::ID,
						type: Phone::VALUE_TYPE_WORK,
					),
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Pager$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 1,
						id: Phone::ID,
						type: Phone::VALUE_TYPE_PAGER,
					),
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Fax$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 1,
						id: Phone::ID,
						type: Phone::VALUE_TYPE_FAX,
					),
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Mobile$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 1,
						id: Phone::ID,
						type: Phone::VALUE_TYPE_MOBILE,
					),
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Other$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 1,
						id: Phone::ID,
						type: Phone::VALUE_TYPE_OTHER,
					),
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Home Address$/i')
				->setCreator(static fn () =>
					Address::generateId(type: EntityAddressType::Home, id: FieldName::ADDRESS_1)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Home City$/i')
				->setCreator(static fn () =>
					Address::generateId(type: EntityAddressType::Home, id: FieldName::CITY)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Home State$/i')
				->setCreator(static fn () =>
					Address::generateId(type: EntityAddressType::Home, id: FieldName::REGION)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Home ZIP$/i')
				->setCreator(static fn () =>
					Address::generateId(type: EntityAddressType::Home, id: FieldName::POSTAL_CODE)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Home Country$/i')
				->setCreator(static fn () =>
					Address::generateId(type: EntityAddressType::Home, id: FieldName::COUNTRY)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Work Address$/i')
				->setCreator(static fn () =>
					Address::generateId(type: EntityAddressType::Work, id: FieldName::ADDRESS_1)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Work City$/i')
				->setCreator(static fn () =>
					Address::generateId(type: EntityAddressType::Work, id: FieldName::CITY)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Work State$/i')
				->setCreator(static fn () =>
					Address::generateId(type: EntityAddressType::Work, id: FieldName::REGION)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Work ZIP$/i')
				->setCreator(static fn () =>
					Address::generateId(type: EntityAddressType::Work, id: FieldName::POSTAL_CODE)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Work Country$/i')
				->setCreator(static fn () =>
					Address::generateId(type: EntityAddressType::Work, id: FieldName::COUNTRY)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Other Street$/i')
				->setCreator(static fn () =>
					Address::generateId(type: EntityAddressType::Primary, id: FieldName::ADDRESS_1)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Other City$/i')
				->setCreator(static fn () =>
					Address::generateId(type: EntityAddressType::Primary, id: FieldName::CITY)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Other State$/i')
				->setCreator(static fn () =>
					Address::generateId(type: EntityAddressType::Primary, id: FieldName::REGION)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Zip Code$/i')
				->setCreator(static fn () =>
					Address::generateId(type: EntityAddressType::Primary, id: FieldName::POSTAL_CODE)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Other Country$/i')
				->setCreator(static fn () =>
					Address::generateId(type: EntityAddressType::Primary, id: FieldName::COUNTRY)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Birthday$/i')
				->setCreator(static fn () => Birthdate::ID),

			(new ByRegexp\Rule())
				->addRegexp('/^Notes$/i')
				->setCreator(static fn () => Comments::ID),

			(new ByRegexp\Rule())
				->addRegexp('/^website$/i')
				->setCreator(static fn () =>
				IndexedMultifield::generateId(
						index: 1,
						id: Web::ID,
						type: Web::VALUE_TYPE_OTHER,
					),
				),
		];

		return new ByRegexp($rules);
	}
}
