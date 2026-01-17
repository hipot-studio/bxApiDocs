<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity;

use Bitrix\Bizproc\Activity\Dto\NodeSettings;

final class ActivityDescription implements \JsonSerializable
{
	public const DEFAULT_ACTIVITY_JS_CLASS = 'BizProcActivity';

	private string $name;
	private string $description;
	private string $aiDescription;
	private array $type;
	private ?string $class = null;
	private ?string $jsClass = null;
	private ?string $nodeType = null;
	private ?NodeSettings $nodeSettings = null;
	private array $category = [];
	private array $locked = [];
	private array $groups = [];
	private string $icon = '';
	private ?int $colorIndex = null;
	private ?int $sort = null;
	private array $return = [];
	private array $additionalResult = [];
	private string $pathToActivity = '';

	private ?array $robotSettings = null;
	private ?array $filter = null;
	private ?array $nodeActionSettings = null;
	private ?array $presets = null;
	private ?string $presetId = null;
	private bool $excluded = false;
	private array $rawData = [];

	public function __construct(
		?string $name,
		?string $description,
		array $type,
	)
	{
		$this->name = (string)$name;
		$this->description = (string)$description;
		$this->type = $type;

		\Bitrix\Main\Loader::includeModule('ui');
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getDescription(): string
	{
		return $this->description;
	}

	public function getType(): array
	{
		return $this->type;
	}

	public function setClass(string $class): self
	{
		$this->class = $class;

		return $this;
	}

	public function getClass(): ?string
	{
		return $this->class;
	}

	public function setJsClass(string $class): self
	{
		$this->jsClass = $class;

		return $this;
	}

	public function getJsClass(): ?string
	{
		return $this->jsClass;
	}

	public function setNodeType(string $type): self
	{
		$this->nodeType = $type;

		return $this;
	}

	public function getNodeType(): ?string
	{
		return $this->nodeType;
	}

	public function setNodeSettings(NodeSettings|array $settings): self
	{
		$this->nodeSettings = is_array($settings) ? NodeSettings::fromArray($settings) : $settings;

		return $this;
	}

	public function getNodeSettings(): ?NodeSettings
	{
		return $this->nodeSettings;
	}

	public function setCategory(array $category): self
	{
		$this->category = $category;

		return $this;
	}

	public function getCategory(): array
	{
		return $this->category;
	}

	public function setLocked(array $locked): self
	{
		$this->locked = $locked;

		return $this;
	}

	public function getLocked(): array
	{
		return $this->locked;
	}

	public function getGroups(): array
	{
		return $this->groups;
	}

	public function setGroups(array $groups): self
	{
		$this->groups = $groups;

		return $this;
	}

	public function getIcon(): string
	{
		return $this->icon;
	}

	public function setIcon(string $icon): self
	{
		$this->icon = $icon;

		return $this;
	}

	public function getColorIndex(): ?int
	{
		return $this->colorIndex;
	}

	public function setColorIndex(int $index): self
	{
		$this->colorIndex = $index;

		return $this;
	}

	public function getSort(): ?int
	{
		return $this->sort;
	}

	public function setSort(int $sort): self
	{
		$this->sort = $sort;

		return $this;
	}

	public function setReturn(array $return): self
	{
		$this->return = $return;

		return $this;
	}

	public function getReturn(): array
	{
		return $this->return;
	}

	public function setAdditionalResult(array $additionalResult): self
	{
		$this->additionalResult = $additionalResult;

		return $this;
	}

	public function getAdditionalResult(): array
	{
		return $this->additionalResult;
	}

	public function setPathToActivity(string $path): self
	{
		$this->pathToActivity = $path;

		return $this;
	}

	public function getPathToActivity(): string
	{
		return $this->pathToActivity;
	}

	public function setRobotSettings(array $settings): self
	{
		$this->robotSettings = $settings;

		return $this;
	}

	public function getRobotSettings(): ?array
	{
		return $this->robotSettings;
	}

	public function setFilter(array $filter): self
	{
		$this->filter = $filter;

		return $this;
	}

	public function getFilter(): ?array
	{
		return $this->filter;
	}

	public function setPresets(array $presets): self
	{
		$this->presets = $presets;

		return $this;
	}

	public function getPresets(): ?array
	{
		return $this->presets;
	}

	public function setPresetId(string $presetId): self
	{
		$this->presetId = $presetId;

		return $this;
	}

	public function getPresetId(): ?string
	{
		return $this->presetId;
	}

	public function applyPreset(array $preset): self
	{
		$data = $this->toArray();

		if (!empty($preset['NAME']))
		{
			$data['NAME'] = $preset['NAME'];
		}

		if (!empty($preset['DESCRIPTION']))
		{
			$data['DESCRIPTION'] = $preset['DESCRIPTION'];
		}

		if (!empty($preset['PROPERTIES']))
		{
			$data['PROPERTIES'] = $preset['PROPERTIES'];
		}

		if (!empty($preset['NODE_ICON']))
		{
			$data['NODE_ICON'] = $preset['NODE_ICON'];
		}

		if (!empty($preset['COLOR_INDEX']))
		{
			$data['COLOR_INDEX'] = $preset['COLOR_INDEX'];
		}

		if (!empty($preset['ID']))
		{
			$data['PRESET_ID'] = $preset['ID'];
		}

		return self::makeFromArray($data);
	}

	public function setExcluded(bool $excluded): self
	{
		$this->excluded = $excluded;

		return $this;
	}

	public function getExcluded(): bool
	{
		return $this->excluded;
	}

	public function set(string $key, mixed $value): self
	{
		// forbidden to change
		if (in_array($key, ['NAME', 'DESCRIPTION', 'TYPE'], true))
		{
			return $this;
		}

		switch ($key)
		{
			case 'CLASS':
				$this->setClass($value);
				break;
			case 'JSCLASS':
				$this->setJsClass($value);
				break;
			case 'NODE_TYPE':
				$this->setNodeType($value);
				break;
			case 'NODE_SETTINGS':
				$this->setNodeSettings($value);
				break;
			case 'CATEGORY':
				$this->setCategory($value);
				break;
			case 'GROUPS':
				$this->setGroups($value);
				break;
			case 'LOCKED':
				$this->setLocked($value);
				break;
			case 'NODE_ICON':
				$this->setIcon($value);
				break;
			case 'COLOR_INDEX':
				$this->setColorIndex($value);
				break;
			case 'SORT':
				$this->setSort($value);
				break;
			case 'RETURN':
				$this->setReturn($value);
				break;
			case 'PATH_TO_ACTIVITY':
				$this->setPathToActivity($value);
				break;
			case 'ROBOT_SETTINGS':
				$this->setRobotSettings($value);
				break;
			case 'EXCLUDED':
				$this->setExcluded($value);
				break;
			case 'FILTER':
				$this->setFilter($value);
				break;
			case 'NODE_ACTION_SETTINGS':
				$this->setNodeActionSettings($value);
				break;
			case 'PRESETS':
				$this->setPresets($value);
				break;
			case 'PRESET_ID':
				$this->setPresetId((string)$value);
				break;
			case 'ADDITIONAL_RESULT':
				$this->setAdditionalResult($value);
				break;
			default:
				$this->rawData[$key] = $value;
		}

		return $this;
	}

	public function get(string $key): mixed
	{
		return match ($key)
		{
			'NAME' => $this->getName(),
			'DESCRIPTION' => $this->getDescription(),
			'AI_DESCRIPTION' => $this->getAiDescription(),
			'TYPE' => $this->getType(),
			'CLASS' => $this->getClass(),
			'JSCLASS' => $this->getJsClass(),
			'NODE_TYPE' => $this->getNodeType(),
			'NODE_SETTINGS' => $this->getNodeSettings(),
			'CATEGORY' => $this->getCategory(),
			'LOCKED' => $this->getLocked(),
			'GROUPS' => $this->getGroups(),
			'NODE_ICON' => $this->getIcon(),
			'COLOR_INDEX' => $this->getColorIndex(),
			'SORT' => $this->getSort(),
			'RETURN' => $this->getReturn(),
			'PATH_TO_ACTIVITY' => $this->getPathToActivity(),
			'ROBOT_SETTINGS' => $this->getRobotSettings(),
			'EXCLUDED' => $this->getExcluded(),
			'FILTER' => $this->getFilter(),
			'NODE_ACTION_SETTINGS' => $this->getNodeActionSettings(),
			'PRESETS' => $this->getPresets(),
			'PRESET_ID' => $this->getPresetId(),
			'ADDITIONAL_RESULT' => $this->getAdditionalResult(),
			default => $this->rawData[$key] ?? null,
		};
	}

	public static function makeFromArray(array $activity): self
	{
		$instance = new self(
			name: $activity['NAME'] ?? '',
			description: $activity['DESCRIPTION'] ?? '',
			type: is_array($activity['TYPE']) ? $activity['TYPE'] : [$activity['TYPE']],
		);

		if (isset($activity['FILTER']) && is_array($activity['FILTER']))
		{
			$instance->setFilter($activity['FILTER']);
		}

		if (isset($activity['COLOR_INDEX']) && is_int($activity['COLOR_INDEX']))
		{
			$instance->setColorIndex($activity['COLOR_INDEX']);
		}

		unset($activity['NAME'], $activity['DESCRIPTION'], $activity['TYPE'], $activity['FILTER'], $activity['COLOR_INDEX']);

		foreach ($activity as $key => $value)
		{
			if (!empty($value))
			{
				$instance->set($key, $value);
			}
		}

		return $instance;
	}

	public function toArray(): array
	{
		$description = [
			'NAME' => $this->getName(),
			'DESCRIPTION' => $this->getDescription(),
			'TYPE' => $this->getType(),

			// specific
			'PATH_TO_ACTIVITY' => $this->getPathToActivity(),
		];

		if ($this->getClass())
		{
			$description['CLASS'] = $this->getClass();
		}

		if ($this->getJsClass())
		{
			$description['JSCLASS'] = $this->getJsClass();
		}

		if ($this->getNodeType())
		{
			$description['NODE_TYPE'] = $this->getNodeType();
		}

		if ($this->getNodeSettings())
		{
			$description['NODE_SETTINGS'] = $this->getNodeSettings()->toArray();
		}

		if ($this->getCategory())
		{
			$description['CATEGORY'] = $this->getCategory();
		}

		if ($this->getLocked())
		{
			$description['LOCKED'] = $this->getLocked();
		}

		if ($this->getGroups())
		{
			$description['GROUPS'] = $this->getGroups();
		}

		if ($this->getIcon())
		{
			$description['NODE_ICON'] = $this->getIcon();
		}

		if ($this->getColorIndex() !== null)
		{
			$description['COLOR_INDEX'] = $this->getColorIndex();
		}

		if ($this->getPresetId() !== null)
		{
			$description['PRESET_ID'] = $this->getPresetId();
		}

		if ($this->getSort() !== null)
		{
			$description['SORT'] = $this->getSort();
		}

		if ($this->getReturn())
		{
			$description['RETURN'] = $this->getReturn();
		}

		if ($this->getRobotSettings())
		{
			$description['ROBOT_SETTINGS'] = $this->getRobotSettings();
		}

		if ($this->getFilter())
		{
			$description['FILTER'] = $this->getFilter();
		}

		if ($this->getExcluded() === true)
		{
			$description['EXCLUDED'] = $this->getExcluded();
		}

		if ($this->getPresets())
		{
			$description['PRESETS'] = $this->getPresets();
		}

		if ($this->getNodeActionSettings())
		{
			$description['NODE_ACTION_SETTINGS'] = $this->getNodeActionSettings();
		}

		if ($this->getAdditionalResult())
		{
			$description['ADDITIONAL_RESULT'] = $this->getAdditionalResult();
		}

		if ($this->rawData)
		{
			$description = array_merge($this->rawData, $description);
		}

		if ($this->getAiDescription())
		{
			$description['AI_DESCRIPTION'] = $this->getAiDescription();
		}

		return $description;
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}

	public function setNodeActionSettings(array $nodeActionSettings): self
	{
		$this->nodeActionSettings = $nodeActionSettings;

		return $this;
	}

	public function getNodeActionSettings(): ?array
	{
		return $this->nodeActionSettings;
	}

	/**
	 * @param string $aiDescription
	 *
	 * @return ActivityDescription
	 */
	public function setAiDescription(string $aiDescription): ActivityDescription
	{
		$this->aiDescription = $aiDescription;

		return $this;
	}

	private function getAiDescription(): string
	{
		return $this->aiDescription ?? '';
	}
}
