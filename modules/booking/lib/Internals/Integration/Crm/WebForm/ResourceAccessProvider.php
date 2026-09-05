<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Crm\WebForm;

use Bitrix\Booking\Internals\Exception\PermissionDenied;
use Bitrix\Crm\WebForm\Internals\FieldTable;
use Bitrix\Crm\WebForm\Internals\FormTable;
use Bitrix\Main\Loader;

class ResourceAccessProvider
{
	private const BOOKING_FIELD_CODE = 'BOOKING_BOOKING';

	public function get(int $formId, string $securityCode): ResourceAccess
	{
		if ($formId <= 0 || $securityCode === '' || !Loader::includeModule('crm'))
		{
			throw new PermissionDenied();
		}

		$form = FormTable::query()
			->setSelect([
				'ID',
				'SECURITY_CODE',
			])
			->where('ID', $formId)
			->where('TYPE_ID', FormTable::TYPE_DEFAULT)
			->where('ACTIVE', true)
			->where('IS_BOOKING_FORM', true)
			->setLimit(1)
			->fetch()
		;

		if ($form === false || !hash_equals((string)$form['SECURITY_CODE'], $securityCode))
		{
			throw new PermissionDenied();
		}

		$fields = FieldTable::query()
			->setSelect(['ID', 'SETTINGS_DATA'])
			->where('FORM_ID', $formId)
			->where('TYPE', FieldTable::TYPE_ENUM_BOOKING)
			->where('CODE', self::BOOKING_FIELD_CODE)
			->setLimit(2)
			->fetchAll()
		;

		if (count($fields) !== 1)
		{
			throw new PermissionDenied();
		}

		return $this->createAccess($fields[0]['SETTINGS_DATA'] ?? null);
	}

	private function createAccess(mixed $storedSettings): ResourceAccess
	{
		$settings = is_array($storedSettings) ? ($storedSettings['settingsData'] ?? null) : null;
		if (!is_array($settings))
		{
			throw new PermissionDenied();
		}

		$branchName = ($settings['isAutoSelectionOn'] ?? null) === true ? 'autoSelection' : 'default';
		$branch = $settings[$branchName] ?? null;
		if (!is_array($branch))
		{
			throw new PermissionDenied();
		}

		if (array_key_exists('resources', $branch))
		{
			return $this->createAccessFromResources($branch['resources']);
		}

		if (!is_array($branch['resourceIds'] ?? null))
		{
			throw new PermissionDenied();
		}

		return new ResourceAccess($branch['resourceIds']);
	}

	private function createAccessFromResources(mixed $resources): ResourceAccess
	{
		if (!is_array($resources))
		{
			throw new PermissionDenied();
		}

		$resourceIds = [];
		$skuIdsByResourceId = [];
		foreach ($resources as $resource)
		{
			if (!is_array($resource) || !array_key_exists('id', $resource) || !is_array($resource['skus'] ?? null))
			{
				throw new PermissionDenied();
			}

			$resourceId = (int)$resource['id'];
			if ($resourceId <= 0)
			{
				continue;
			}

			$resourceIds[] = $resourceId;
			$skuIdsByResourceId[$resourceId] = array_merge(
				$skuIdsByResourceId[$resourceId] ?? [],
				$resource['skus'],
			);
		}

		return new ResourceAccess($resourceIds, $skuIdsByResourceId);
	}
}
