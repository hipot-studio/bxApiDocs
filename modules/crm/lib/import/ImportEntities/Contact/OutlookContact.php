<?php

namespace Bitrix\Crm\Import\ImportEntities\Contact;

use Bitrix\Crm\Address\Enum\FieldName;
use Bitrix\Crm\EntityAddressType;
use Bitrix\Crm\Import\Collection\FieldCollection;
use Bitrix\Crm\Import\Contract\ImportEntityInterface;
use Bitrix\Crm\Import\Contract\Strategy\FieldBindingMapperInterface;
use Bitrix\Crm\Import\Dto\Entity\AbstractImportSettings;
use Bitrix\Crm\Import\Dto\Entity\ImportSettings\ContactImportSettings;
use Bitrix\Crm\Import\Hook\PostSaveHooks\MultipleSaveAddress;
use Bitrix\Crm\Import\ImportEntityFields\Address;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Birthdate;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Comments;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CompanyId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Honorific;
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

final class OutlookContact implements
	ImportEntityInterface,
	ImportEntityInterface\HasPostSaveHooksInterface
{
	private ?FieldCollection $fields = null;

	public function __construct(
		private readonly ContactImportSettings $settings,
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
			new Honorific(CCrmOwnerType::Contact),
			new BirthDate(CCrmOwnerType::Contact),

			new CompanyId(CCrmOwnerType::Contact),
			new Post(CCrmOwnerType::Contact),

			// Email 1
			new IndexedMultifield(
				index: 1,
				id: Email::ID,
				type: Email::VALUE_TYPE_OTHER,
			),

			// Email 2
			new IndexedMultifield(
				index: 2,
				id: Email::ID,
				type: Email::VALUE_TYPE_OTHER,
			),

			// Email 3
			new IndexedMultifield(
				index: 3,
				id: Email::ID,
				type: Email::VALUE_TYPE_OTHER,
			),

			// Home Phone 1
			new IndexedMultifield(
				index: 1,
				id: Phone::ID,
				type: Phone::VALUE_TYPE_HOME,
			),

			// Home Phone 2
			new IndexedMultifield(
				index: 2,
				id: Phone::ID,
				type: Phone::VALUE_TYPE_HOME,
			),

			// Business Phone 1
			new IndexedMultifield(
				index: 1,
				id: Phone::ID,
				type: Phone::VALUE_TYPE_WORK,
			),

			// Business Phone 2
			new IndexedMultifield(
				index: 2,
				id: Phone::ID,
				type: Phone::VALUE_TYPE_WORK,
			),

			// Mobile Phone
			new IndexedMultifield(
				index: 1,
				id: Phone::ID,
				type: Phone::VALUE_TYPE_MOBILE,
			),

			// Pager
			new IndexedMultifield(
				index: 1,
				id: Phone::ID,
				type: Phone::VALUE_TYPE_PAGER,
			),

			// Business Fax
			new IndexedMultifield(
				index: 1,
				id: Phone::ID,
				type: Phone::VALUE_TYPE_FAX,
			),

			// Home Fax
			new IndexedMultifield(
				index: 2,
				id: Phone::ID,
				type: Phone::VALUE_TYPE_FAX,
			),

			// Other Fax
			new IndexedMultifield(
				index: 3,
				id: Phone::ID,
				type: Phone::VALUE_TYPE_FAX,
			),

			// Car Phone (Other Phone 1)
			new IndexedMultifield(
				index: 1,
				id: Phone::ID,
				type: Phone::VALUE_TYPE_OTHER,
			),

			// Other Phone (Other Phone 2)
			new IndexedMultifield(
				index: 2,
				id: Phone::ID,
				type: Phone::VALUE_TYPE_OTHER,
			),

			// PrimaryPhone (Other Phone 3)
			new IndexedMultifield(
				index: 3,
				id: Phone::ID,
				type: Phone::VALUE_TYPE_OTHER,
			),

			// Company Main Phone
			new IndexedMultifield(
				index: 4,
				id: Phone::ID,
				type: Phone::VALUE_TYPE_OTHER,
			),

			// Callback
			new IndexedMultifield(
				index: 5,
				id: Phone::ID,
				type: Phone::VALUE_TYPE_OTHER,
			),

			// Radio Phone
			new IndexedMultifield(
				index: 6,
				id: Phone::ID,
				type: Phone::VALUE_TYPE_OTHER,
			),

			// Telex
			new IndexedMultifield(
				index: 7,
				id: Phone::ID,
				type: Phone::VALUE_TYPE_OTHER,
			),

			// TTY/TDD Phone
			new IndexedMultifield(
				index: 8,
				id: Phone::ID,
				type: Phone::VALUE_TYPE_OTHER,
			),

			// Business Address
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

			// Personal Web Page
			new IndexedMultifield(
				index: 1,
				id: Web::ID,
				type: Web::VALUE_TYPE_HOME,
			),

			// Web Page
			new IndexedMultifield(
				index: 1,
				id: Web::ID,
				type: Web::VALUE_TYPE_OTHER,
			),

			new Comments(CCrmOwnerType::Contact),
		]);

		return $this->fields;
	}

	public function getSettings(): AbstractImportSettings
	{
		return $this->settings;
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
				->addRegexp('/^Title$/i')
				->setCreator(static fn () => Post::ID),

			(new ByRegexp\Rule())
				->addRegexp('/^Suffix$/i')
				->setCreator(static fn () => Honorific::ID),

			(new ByRegexp\Rule())
				->addRegexp('/^Nickname$/i')
				->setCreator(static fn () => null),

			(new ByRegexp\Rule())
				->addRegexp('/^Given Yomi$/i')
				->setCreator(static fn () => null),

			(new ByRegexp\Rule())
				->addRegexp('/^Surname Yomi$/i')
				->setCreator(static fn () => null),

			(new ByRegexp\Rule())
				->addRegexp('/^E-mail Address$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 1,
						id: Email::ID,
						type: Email::VALUE_TYPE_OTHER,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^E-mail 2 Address$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 2,
						id: Email::ID,
						type: Email::VALUE_TYPE_OTHER,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^E-mail 3 Address$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 3,
						id: Email::ID,
						type: Email::VALUE_TYPE_OTHER,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Home Phone$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 1,
						id: Phone::ID,
						type: Phone::VALUE_TYPE_HOME,
					),
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Home Phone 2$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 2,
						id: Phone::ID,
						type: Phone::VALUE_TYPE_HOME,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Business Phone$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 1,
						id: Phone::ID,
						type: Phone::VALUE_TYPE_WORK,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Business Phone 2$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 2,
						id: Phone::ID,
						type: Phone::VALUE_TYPE_WORK,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Mobile Phone$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 1,
						id: Phone::ID,
						type: Phone::VALUE_TYPE_MOBILE,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Pager$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 1,
						id: Phone::ID,
						type: Phone::VALUE_TYPE_PAGER,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Business Fax$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 1,
						id: Phone::ID,
						type: Phone::VALUE_TYPE_FAX,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Home Fax$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 2,
						id: Phone::ID,
						type: Phone::VALUE_TYPE_FAX,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Other Fax$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 3,
						id: Phone::ID,
						type: Phone::VALUE_TYPE_FAX,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Car Phone$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 1,
						id: Phone::ID,
						type: Phone::VALUE_TYPE_OTHER,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Other Phone$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 2,
						id: Phone::ID,
						type: Phone::VALUE_TYPE_OTHER,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Primary Phone$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 3,
						id: Phone::ID,
						type: Phone::VALUE_TYPE_OTHER,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Company Main Phone$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 4,
						id: Phone::ID,
						type: Phone::VALUE_TYPE_OTHER,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Callback$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 5,
						id: Phone::ID,
						type: Phone::VALUE_TYPE_OTHER,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Radio Phone$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 6,
						id: Phone::ID,
						type: Phone::VALUE_TYPE_OTHER,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Telex$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 7,
						id: Phone::ID,
						type: Phone::VALUE_TYPE_OTHER,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^TTY\/TDD Phone$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 8,
						id: Phone::ID,
						type: Phone::VALUE_TYPE_OTHER,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^IMAddress$/i')
				->setCreator(static fn () => null),

			(new ByRegexp\Rule())
				->addRegexp('/^Job Title$/i')
				->setCreator(static fn () => Post::ID),

			(new ByRegexp\Rule())
				->addRegexp('/^Department$/i')
				->setCreator(static fn () => null),

			(new ByRegexp\Rule())
				->addRegexp('/^Company$/i')
				->setCreator(static fn () => CompanyId::ID),

			(new ByRegexp\Rule())
				->addRegexp('/^Office Location$/i')
				->setCreator(static fn () => null),

			(new ByRegexp\Rule())
				->addRegexp("/^Manager's Name$/i")
				->setCreator(static fn () => null),

			(new ByRegexp\Rule())
				->addRegexp("/^Assistant's Name$/i")
				->setCreator(static fn () => null),

			(new ByRegexp\Rule())
				->addRegexp("/^Assistant's Phone$/i")
				->setCreator(static fn () => null),

			(new ByRegexp\Rule())
				->addRegexp('/^Company Yomi$/i')
				->setCreator(static fn () => null),

			(new ByRegexp\Rule())
				->addRegexp('/^Business Street$/i')
				->setCreator(static fn () =>
					Address::generateId(
						type: EntityAddressType::Work,
						id: FieldName::ADDRESS_1,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Business City$/i')
				->setCreator(static fn () =>
					Address::generateId(
						type: EntityAddressType::Work,
						id: FieldName::CITY,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Business State$/i')
				->setCreator(static fn () =>
					Address::generateId(
						type: EntityAddressType::Work,
						id: FieldName::REGION,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Business Postal Code$/i')
				->setCreator(static fn () =>
					Address::generateId(
						type: EntityAddressType::Work,
						id: FieldName::POSTAL_CODE,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Business Country\/Region$/i')
				->setCreator(static fn () =>
					Address::generateId(
						type: EntityAddressType::Work,
						id: FieldName::COUNTRY,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Home Street$/i')
				->setCreator(static fn () =>
					Address::generateId(
						type: EntityAddressType::Home,
						id: FieldName::ADDRESS_1,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Home City$/i')
				->setCreator(static fn () =>
					Address::generateId(
						type: EntityAddressType::Home,
						id: FieldName::CITY,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Home State$/i')
				->setCreator(static fn () =>
					Address::generateId(
						type: EntityAddressType::Home,
						id: FieldName::REGION,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Home Postal Code$/i')
				->setCreator(static fn () =>
					Address::generateId(
						type: EntityAddressType::Home,
						id: FieldName::POSTAL_CODE,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Home Country\/Region$/i')
				->setCreator(static fn () =>
					Address::generateId(
						type: EntityAddressType::Home,
						id: FieldName::COUNTRY,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Other Street$/i')
				->setCreator(static fn () =>
					Address::generateId(
						type: EntityAddressType::Primary,
						id: FieldName::ADDRESS_1,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Other City$/i')
				->setCreator(static fn () =>
					Address::generateId(
						type: EntityAddressType::Primary,
						id: FieldName::CITY,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Other State$/i')
				->setCreator(static fn () =>
					Address::generateId(
						type: EntityAddressType::Primary,
						id: FieldName::REGION,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Other Postal Code$/i')
				->setCreator(static fn () =>
					Address::generateId(
						type: EntityAddressType::Primary,
						id: FieldName::POSTAL_CODE,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Other Country\/Region$/i')
				->setCreator(static fn () =>
					Address::generateId(
						type: EntityAddressType::Primary,
						id: FieldName::COUNTRY,
					)
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Personal Web Page$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 1,
						id: Web::ID,
						type: Web::VALUE_TYPE_HOME,
					),
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Spouse$/i')
				->setCreator(static fn () => null),

			(new ByRegexp\Rule())
				->addRegexp('/^Schools$/i')
				->setCreator(static fn () => null),

			(new ByRegexp\Rule())
				->addRegexp('/^Hobby$/i')
				->setCreator(static fn () => null),

			(new ByRegexp\Rule())
				->addRegexp('/^Location$/i')
				->setCreator(static fn () => null),

			(new ByRegexp\Rule())
				->addRegexp('/^Web Page$/i')
				->setCreator(static fn () =>
					IndexedMultifield::generateId(
						index: 1,
						id: Web::ID,
						type: Web::VALUE_TYPE_OTHER,
					),
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Birthday$/i')
				->setCreator(static fn () => Birthdate::ID),

			(new ByRegexp\Rule())
				->addRegexp('/^Anniversary$/i')
				->setCreator(static fn () => null),

			(new ByRegexp\Rule())
				->addRegexp('/^Notes$/i')
				->setCreator(static fn () => Comments::ID),
		];

		return new ByRegexp($rules);
	}

	public function getPostSaveHooks(): array
	{
		return [
			new MultipleSaveAddress(),
		];
	}
}
