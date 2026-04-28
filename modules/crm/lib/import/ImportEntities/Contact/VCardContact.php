<?php

namespace Bitrix\Crm\Import\ImportEntities\Contact;

use Bitrix\Crm\Import\Collection\FieldCollection;
use Bitrix\Crm\Import\Contract\ImportEntityInterface;
use Bitrix\Crm\Import\Contract\Strategy\FieldBindingMapperInterface;
use Bitrix\Crm\Import\Dto\Entity\AbstractImportSettings;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Dto\Entity\ImportSettings\ContactImportSettings;
use Bitrix\Crm\Import\Enum\VCard\Column;
use Bitrix\Crm\Import\Hook\PostSaveHooks\MultipleSaveAddress;
use Bitrix\Crm\Import\Hook\PostSaveHooks\VCard\SaveCompany;
use Bitrix\Crm\Import\ImportEntityFields\VCard\Communication\Email;
use Bitrix\Crm\Import\ImportEntityFields\VCard\Communication\Phone;
use Bitrix\Crm\Import\ImportEntityFields\VCard\DeliveryAddressing\Address;
use Bitrix\Crm\Import\ImportEntityFields\VCard\Explanatory\Comment;
use Bitrix\Crm\Import\ImportEntityFields\VCard\Explanatory\Url;
use Bitrix\Crm\Import\ImportEntityFields\VCard\Identification\Birthday;
use Bitrix\Crm\Import\ImportEntityFields\VCard\Identification\Name;
use Bitrix\Crm\Import\ImportEntityFields\VCard\Identification\Photo;
use Bitrix\Crm\Import\ImportEntityFields\VCard\Organization\CompanyLogo;
use Bitrix\Crm\Import\ImportEntityFields\VCard\Organization\CompanyTitle;
use Bitrix\Crm\Import\ImportEntityFields\VCard\Organization\Post;
use Bitrix\Crm\Import\Strategy\FieldBindingMapper\StaticFieldBindings;

final class VCardContact implements ImportEntityInterface, ImportEntityInterface\HasPostSaveHooksInterface
{
	private ?FieldCollection $fieldCollection = null;

	public function __construct(
		private readonly ContactImportSettings $settings,
	)
	{
	}

	public function getFields(): FieldCollection
	{
		if ($this->fieldCollection !== null)
		{
			return $this->fieldCollection;
		}

		$this->fieldCollection = new FieldCollection([
			new Name(),
			new Photo(),
			new Birthday(),

			new Address(),

			new Phone(),
			new Email(),
			new Url(),

			new CompanyTitle(),
			new CompanyLogo(),
			new Post(),

			new Comment(),
		]);

		return $this->fieldCollection;
	}

	public function getSettings(): AbstractImportSettings
	{
		return $this->settings;
	}

	public function getFieldBindingMapper(): FieldBindingMapperInterface
	{
		$fieldBindings = new FieldBindings();

		$fieldBindings->set(
			new FieldBindings\Binding(
				fieldId: Name::ID,
				columnIndex: Column::Name->index(),
			),
		);

		$fieldBindings->set(
			new FieldBindings\Binding(
				fieldId: Photo::ID,
				columnIndex: Column::Photo->index(),
			),
		);

		$fieldBindings->set(
			new FieldBindings\Binding(
				fieldId: Birthday::ID,
				columnIndex: Column::Birthday->index(),
			)
		);

		$fieldBindings->set(
			new FieldBindings\Binding(
				fieldId: Address::ID,
				columnIndex: Column::Address->index(),
			)
		);

		$fieldBindings->set(
			new FieldBindings\Binding(
				fieldId: Phone::ID,
				columnIndex: Column::Telephone->index(),
			)
		);

		$fieldBindings->set(
			new FieldBindings\Binding(
				fieldId: Email::ID,
				columnIndex: Column::Email->index(),
			)
		);

		$fieldBindings->set(
			new FieldBindings\Binding(
				fieldId: Url::ID,
				columnIndex: Column::Url->index(),
			),
		);

		$fieldBindings->set(
			new FieldBindings\Binding(
				fieldId: CompanyTitle::ID,
				columnIndex: Column::Organization->index(),
			),
		);

		$fieldBindings->set(
			new FieldBindings\Binding(
				fieldId: CompanyLogo::ID,
				columnIndex: Column::Logo->index(),
			),
		);

		$fieldBindings->set(
			new FieldBindings\Binding(
				fieldId: Post::ID,
				columnIndex: Column::Title->index(),
			),
		);

		$fieldBindings->set(
			new FieldBindings\Binding(
				fieldId: Comment::ID,
				columnIndex: Column::Note->index(),
			)
		);

		return new StaticFieldBindings($fieldBindings);
	}

	public function getPostSaveHooks(): array
	{
		return [
			new MultipleSaveAddress(),
			new SaveCompany(),
		];
	}
}
