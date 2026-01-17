<?php

namespace Bitrix\Crm\Feature\Entity;

use Bitrix\Main\IO\Path;
use Bitrix\Crm\Feature\BaseFeature;

class FeatureRepository
{
	public function getAllByCategory(): array
	{
		$classes = $this->getAllFeaturesClasses();
		$result = [];
		foreach ($classes as $class)
		{
			/** @var BaseFeature $feature */
			$feature = new $class;
			$categoryId = $feature->getCategory()->getId();
			if (!isset($result[$categoryId]))
			{
				try
				{
					$categoryName = $feature->getCategory()->getName();
				}
				catch (\Throwable $e)
				{
					$categoryName = basename(get_class($feature->getCategory()));
				}
				$result[$categoryId] = [
					'name' => $categoryName,
					'sort' => $feature->getCategory()->getSort(),
					'items' => [],
				];
			}

			$host = \Bitrix\Main\Engine\UrlManager::getInstance()->getHostUrl();
			$secretLink = $host . '/crm/configs/?enableFeature=' . $feature->getId();

			try
			{
				$featureName = $feature->getName();
			}
			catch (\Throwable $e)
			{
				$featureName = basename(get_class($feature));
			}
			$result[$categoryId]['items'][] = [
				'name' => $featureName,
				'id' => $feature->getId(),
				'sort' => $feature->getSort(),
				'enabled' => $feature->isEnabled(),
				'secretLink' => $feature->allowSwitchBySecretLink() ? $secretLink : null,
			];
		}
		\Bitrix\Main\Type\Collection::sortByColumn($result, 'sort');

		foreach ($result as &$category)
		{
			\Bitrix\Main\Type\Collection::sortByColumn($category['items'], 'sort');
		}

		return $result;
	}

	public function getById(string $id): ?BaseFeature
	{
		$classes = $this->getAllFeaturesClasses();
		foreach ($classes as $class)
		{
			/** @var BaseFeature $feature */
			$feature = new $class;
			if ($feature->getId() === $id)
			{
				return $feature;
			}
		}

		return null;
	}

	private function getAllFeaturesClasses(): array
	{
		$result = [];

		$baseDir = Path::normalize(__DIR__ . '/../');
		$files = scandir($baseDir);
		foreach ($files as $file)
		{
			if (preg_match('/(.*)\.php/', $file, $matches))
			{
				$className = '\\Bitrix\\Crm\\Feature\\' . $matches[1];
				if (is_subclass_of($className, BaseFeature::class))
				{
					$result[] = $className;
				}
			}
		}

		return $result;
	}
}
