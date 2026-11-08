<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\LegalRegistry;

use Bitrix\Crm\WebForm\Form;
use Bitrix\Crm\WebForm\Internals\FormTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;

/** The live legacy form is read only here; no legacy object escapes this gateway. */
class FormSourceGateway
{
	public static function getInstance(): self
	{
		$locator = ServiceLocator::getInstance();
		$gateway = $locator->has(self::class) ? $locator->get(self::class) : null;

		return $gateway instanceof self ? $gateway : new self();
	}

	public function captureLiveForm(int $formId): array
	{
		if ($formId <= 0 || $formId > 2147483647) { throw new \UnexpectedValueException('NOT_FOUND_OR_DENIED'); }
		$form = $this->createForm();
		if (!$form->loadOnlyForm($formId) || !$form->isActive())
		{
			throw new \UnexpectedValueException('NOT_FOUND_OR_DENIED');
		}
		if (!$form->load($formId)) { throw new \UnexpectedValueException('NOT_FOUND_OR_DENIED'); }
		$data = $form->get(); $fields = [];
		foreach ($form->getFields() as $field) { $fields[] = (string)($field['CAPTION'] ?? ''); }

		return ['id' => $formId, 'name' => $form->getName(),
			'languageId' => (string)($data['LANGUAGE_ID'] ?? LANGUAGE_ID), 'fields' => $fields];
	}

	/** Inactive forms still exist. URLs use the configured CRM edit route without per-form landing queries. */
	public function readCurrentForms(array $formIds): array
	{
		$ids = [];
		foreach ($formIds as $id)
		{
			if (!is_int($id) || $id <= 0 || $id > 2147483647) { throw new \InvalidArgumentException('INVALID_PAYLOAD'); }
			$ids[$id] = $id;
		}
		if ($ids === []) { return []; }
		$rows = FormTable::getList(['select' => ['ID', 'NAME'], 'filter' => ['@ID' => array_values($ids)]])->fetchAll();
		$byId = array_column($rows, null, 'ID'); $result = [];
		$route = Option::get('crm', 'path_to_webform_edit', '/crm/webform/edit/#form_id#/');
		foreach ($ids as $id)
		{
			$exists = isset($byId[$id]);
			$url = $exists ? str_replace('#form_id#', (string)$id, $route) : null;
			if ($url !== null && (!str_starts_with($url, '/') || str_starts_with($url, '//'))) { $url = null; }
			$result[$id] = ['id' => $id, 'name' => $exists ? (string)$byId[$id]['NAME'] : null,
				'exists' => $exists, 'url' => $url];
		}

		return $result;
	}

	protected function createForm(): Form { return new Form(); }
}
