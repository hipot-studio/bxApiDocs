<?php

namespace Bitrix\Crm\Import\ImportEntities\Contact;

use Bitrix\Crm\Address\Enum\FieldName;
use Bitrix\Crm\Import\Collection\FieldCollection;
use Bitrix\Crm\Import\Contract\ImportEntityInterface;
use Bitrix\Crm\Import\Contract\Strategy\FieldBindingMapperInterface;
use Bitrix\Crm\Import\Dto\Entity\AbstractImportSettings;
use Bitrix\Crm\Import\Dto\Entity\HeaderFieldCreateRule;
use Bitrix\Crm\Import\Dto\Entity\ImportSettings\ContactImportSettings;
use Bitrix\Crm\Import\Factory\ImportEntityFieldFactory;
use Bitrix\Crm\Import\Hook\PostSaveHooks\MultipleSaveAddress;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Birthdate;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Comments;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CompanyId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Contact\Photo;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Honorific;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\LastName;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Name;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Post;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\SecondName;
use Bitrix\Crm\Import\ImportEntityFields\Gmail\Address;
use Bitrix\Crm\Import\ImportEntityFields\Gmail\MultifieldType;
use Bitrix\Crm\Import\ImportEntityFields\Gmail\MultifieldValue;
use Bitrix\Crm\Import\Strategy\FieldBindingMapper\ByRegexp;
use Bitrix\Crm\Multifield\Type\Email;
use Bitrix\Crm\Multifield\Type\Im;
use Bitrix\Crm\Multifield\Type\Phone;
use Bitrix\Crm\Multifield\Type\Web;
use CCrmOwnerType;

final class GmailContact implements
	ImportEntityInterface,
	ImportEntityInterface\DependOnHeadersInterface,
	ImportEntityInterface\HasPostSaveHooksInterface
{
	private ?FieldCollection $fields = null;
	private ImportEntityFieldFactory $fieldFactory;

	private array $headers = [];

	public function __construct(
		private readonly ContactImportSettings $settings,
	)
	{
		$this->fieldFactory = new ImportEntityFieldFactory(CCrmOwnerType::Contact);
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
			new CompanyId(CCrmOwnerType::Contact),
			new Post(CCrmOwnerType::Contact),
			new BirthDate(CCrmOwnerType::Contact),
			new Comments(CCrmOwnerType::Contact),
			new Photo(CCrmOwnerType::Contact),
		]);

		/**
		 * @var HeaderFieldCreateRule[] $headerFieldCreateRules
		 */
		$headerFieldCreateRules = [
			(new HeaderFieldCreateRule())
				->addRule('/^E-mail (?<index>\d+) - Label$/i')
				->addRule('/^E-mail (?<index>\d+) - Type/i') // mailru
				->setCreator(static fn (array $matches) =>
					new MultifieldType(id: Email::ID, index: $matches['index'], default: Email::VALUE_TYPE_OTHER)
				),

			(new HeaderFieldCreateRule())
				->addRule('/^E-mail (?<index>\d+) - Value$/i')
				->setCreator(static fn (array $matches) =>
					new MultifieldValue(id: Email::ID, index: $matches['index'])
				),

			(new HeaderFieldCreateRule())
				->addRule('/^Phone (?<index>\d+) - Label$/i')
				->addRule('/^Phone (?<index>\d+) - Type/i') // mailru
				->setCreator(static fn (array $matches) =>
					new MultifieldType(id: Phone::ID, index: $matches['index'], default: Phone::VALUE_TYPE_OTHER)
				),

			(new HeaderFieldCreateRule())
				->addRule('/^Phone (?<index>\d+) - Value$/i')
				->setCreator(static fn (array $matches) =>
					new MultifieldValue(id: Phone::ID, index: $matches['index'])
				),

			(new HeaderFieldCreateRule())
				->addRule('/^Website (?<index>\d+) - Label$/i')
				->addRule('/^Website (?<index>\d+) - Type/i') // mailru
				->setCreator(static fn (array $matches) =>
					new MultifieldType(id: Web::ID, index: $matches['index'], default: Web::VALUE_TYPE_OTHER),
				),

			(new HeaderFieldCreateRule())
				->addRule('/^Website (?<index>\d+) - Value$/i')
				->setCreator(static fn (array $matches) =>
					new MultifieldValue(id: Web::ID, index: $matches['index'])
				),

			// mailru
			(new HeaderFieldCreateRule())
				->addRule('/^IM (?<index>\d+) - Service/i')
				->setCreator(static fn (array $matches) =>
					new MultifieldType(id: Im::ID, index: $matches['index'], default: Im::VALUE_TYPE_OTHER),
				),

			(new HeaderFieldCreateRule())
				->addRule('/^IM (?<index>\d+) - Value$/i')
				->setCreator(static fn (array $matches) =>
					new MultifieldValue(id: Im::ID, index: $matches['index'])
				)
		];

		foreach ($this->headers as $header)
		{
			foreach ($headerFieldCreateRules as $headerFieldCreateRule)
			{
				$field =  $headerFieldCreateRule->match($header);
				if ($field !== null)
				{
					$this->fields->push($field);
				}
			}
		}

		$this->fields->pushList([
			new Address(id: FieldName::FULL_ADDRESS),
			new Address(id: FieldName::ADDRESS_1),
			new Address(id: FieldName::ADDRESS_2),
			new Address(id: FieldName::CITY),
			new Address(id: FieldName::REGION),
			new Address(id: FieldName::POSTAL_CODE),
			new Address(id: FieldName::COUNTRY_CODE),
		]);

		$this->fields->merge($this->fieldFactory->getUserFields());

		return $this->fields;
	}

	public function getSettings(): AbstractImportSettings
	{
		return $this->settings;
	}

	public function getFieldBindingMapper(): FieldBindingMapperInterface
	{
		$googleRules = [
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
				->addRegexp('/^Name Prefix$/i')
				->setCreator(static fn () => Honorific::ID),

			(new ByRegexp\Rule())
				->addRegexp('/^Organization Name$/i')
				->setCreator(static fn () => CompanyId::ID),

			(new ByRegexp\Rule())
				->addRegexp('/^Organization Title$/i')
				->setCreator(static fn () => Post::ID),

			(new ByRegexp\Rule())
				->addRegexp('/^Birthday$/i')
				->setCreator(static fn () => BirthDate::ID),

			(new ByRegexp\Rule())
				->addRegexp('/^Notes$/i')
				->setCreator(static fn () => Comments::ID),

			(new ByRegexp\Rule())
				->addRegexp('/^Photo$/i')
				->setCreator(static fn () => Photo::ID),

			(new ByRegexp\Rule())
				->addRegexp('/^E-mail (?<index>\d+) - Label$/i')
				->setCreator(static fn (array $matches) =>
					MultifieldType::generateId(id: Email::ID, index: $matches['index'])
				),

			(new ByRegexp\Rule())
				->addRegexp('/^E-mail (?<index>\d+) - Value$/i')
				->setCreator(static fn (array $matches) =>
					MultifieldValue::generateId(id: Email::ID, index: $matches['index'])
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Phone (?<index>\d+) - Label$/i')
				->setCreator(static fn (array $matches) =>
					MultifieldType::generateId(id: Phone::ID, index: $matches['index'])
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Phone (?<index>\d+) - Value$/i')
				->setCreator(static fn (array $matches) =>
					MultifieldValue::generateId(id: Phone::ID, index: $matches['index'])
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Website (?<index>\d+) - Label$/i')
				->setCreator(static fn (array $matches) =>
					MultifieldType::generateId(id: Web::ID, index: $matches['index'])
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Website (?<index>\d+) - Value$/i')
				->setCreator(static fn (array $matches) =>
					MultifieldValue::generateId(id: Web::ID, index: $matches['index'])
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Address 1 - Formatted$/i')
				->setCreator(static fn () => FieldName::FULL_ADDRESS),

			(new ByRegexp\Rule())
				->addRegexp('/^Address 1 - Street$/i')
				->setCreator(static fn () => FieldName::ADDRESS_1),

			(new ByRegexp\Rule())
				->addRegexp('/^Address 1 - City$/i')
				->setCreator(static fn () => FieldName::CITY),

			(new ByRegexp\Rule())
				->addRegexp('/^Address 1 - PO Box$/i')
				->setCreator(static fn () => null),

			(new ByRegexp\Rule())
				->addRegexp('/^Address 1 - Region$/i')
				->setCreator(static fn () => FieldName::REGION),

			(new ByRegexp\Rule())
				->addRegexp('/^Address 1 - Postal Code$/i')
				->setCreator(static fn () => FieldName::POSTAL_CODE),

			(new ByRegexp\Rule())
				->addRegexp('/^Address 1 - Country$/i')
				->setCreator(static fn () => FieldName::COUNTRY_CODE),

			(new ByRegexp\Rule())
				->addRegexp('/^Address 1 - Extended Address$/i')
				->setCreator(static fn () => FieldName::ADDRESS_2),
		];

		$mailRules = [
			(new ByRegexp\Rule())
				->addRegexp('/^Given Name$/i')
				->setCreator(static fn () => Name::ID),

			(new ByRegexp\Rule())
				->addRegexp('/^Additional Name$/i')
				->setCreator(static fn () => SecondName::ID),

			(new ByRegexp\Rule())
				->addRegexp('/^Family Name$/i')
				->setCreator(static fn () => LastName::ID),

			(new ByRegexp\Rule())
				->addRegexp('/^Organization 1 - Name$/i')
				->setCreator(static fn () => CompanyId::ID),

			(new ByRegexp\Rule())
				->addRegexp('/^Organization 1 - Title$/i')
				->setCreator(static fn () => Post::ID),

			(new ByRegexp\Rule())
				->addRegexp('/^E-mail (?<index>\d+) - Type$/i')
				->setCreator(static fn (array $matches) =>
					MultifieldType::generateId(id: Email::ID, index: $matches['index'])
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Phone (?<index>\d+) - Type$/i')
				->setCreator(static fn (array $matches) =>
					MultifieldType::generateId(id: Phone::ID, index: $matches['index'])
				),

			(new ByRegexp\Rule())
				->addRegexp('/^Website (?<index>\d+) - Type$/i')
				->setCreator(static fn (array $matches) =>
					MultifieldType::generateId(id: Web::ID, index: $matches['index'])
				),

			(new ByRegexp\Rule())
				->addRegexp('/^IM (?<index>\d+) - Service$/i')
				->setCreator(static fn (array $matches) =>
					MultifieldType::generateId(id: Im::ID, index: $matches['index'])
				),

			(new ByRegexp\Rule())
				->addRegexp('/^IM (?<index>\d+) - Value$/i')
				->setCreator(static fn (array $matches) =>
					MultifieldValue::generateId(id: Im::ID, index: $matches['index'])
				),
		];

		return new ByRegexp(array_merge($googleRules, $mailRules));
	}

	public function setHeaders(array $headers): self
	{
		$this->headers = $headers;

		return $this;
	}

	public function getPostSaveHooks(): array
	{
		return [
			new MultipleSaveAddress(),
		];
	}
}
