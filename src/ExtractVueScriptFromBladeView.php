<?php

namespace ProtoneMedia\SpladeCore;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class ExtractVueScriptFromBladeView
{
    protected readonly string $originalScript;

    protected string $viewWithoutScriptTag;

    protected ScriptParser $scriptParser;

    public function __construct(
        protected readonly string $originalView,
        protected readonly array $data,
        protected readonly string $bladePath
    ) {
    }

    /**
     * Helper method to create a new instance.
     */
    public static function from(string $originalView, array $data, string $bladePath): self
    {
        return new static($originalView, $data, $bladePath);
    }

    /**
     * Handle the extraction of the Vue script. Returns the view without the <script setup> tag.
     */
    public function handle(Filesystem $filesystem): string
    {
        if (! str_starts_with(trim($this->originalView), '<script setup>')) {
            // The view does not contain a <script setup> tag, so we don't need to do anything.
            return $this->originalView;
        }

        $this->splitOriginalView();
        $this->scriptParser = new ScriptParser($this->originalScript);

        // Some pre-processing of the view.
        $this->viewWithoutScriptTag = $this->replaceComponentMethodLoadingStates($this->viewWithoutScriptTag);
        $this->viewWithoutScriptTag = $this->replaceElementRefs($this->viewWithoutScriptTag);

        // Adjust the current defineProps, or generate a new one if it didn't exist yet.
        [$script, $defineProps] = $this->extractDefinePropsFromScript();

        $vueComponent = implode(PHP_EOL, array_filter([
            '<script setup>',
            $this->renderImports(),
            $defineProps,
            $this->renderSpladeBridgeState(),
            $this->renderBladeFunctionsAsJavascriptFunctions(),
            $this->renderBladePropertiesAsComputedVueProperties(),
            $this->renderJavascriptFunctionToRefreshComponent(),
            $this->renderElementRefStoreAndSetter(),
            $script,
            $this->renderSpladeRenderFunction(),
            '</script>',
            '<template><spladeRender /></template>',
        ]));

        $directory = config('splade-core.compiled_scripts');
        $filesystem->ensureDirectoryExists($directory);
        $filesystem->put($vuePath = $directory.DIRECTORY_SEPARATOR."{$this->getTag()}.vue", $vueComponent);

        if (config('splade-core.prettify_compiled_scripts')) {
            Process::path(base_path())->run("node_modules/.bin/eslint --fix {$vuePath}");
        }

        return $this->viewWithoutScriptTag;
    }

    /**
     * Check if the view uses custom bound attributes.
     */
    protected function attributesAreCustomBound(): bool
    {
        return str_contains($this->originalView, 'v-bind="$attrs"');
    }

    /**
     * Get the functions that are passed from the Blade Component.
     */
    protected function getBladeFunctions(): array
    {
        return $this->data['spladeBridge']['functions'];
    }

    /**
     * Get the properties that are passed from the Blade Component.
     */
    protected function getBladeProperties(): array
    {
        return array_keys($this->data['spladeBridge']['data']);
    }

    /**
     * Get the 'Splade' tag of the Blade Component.
     */
    protected function getTag(): string
    {
        return $this->data['spladeBridge']['tag'];
    }

    /**
     * Check if the view uses the refreshComponent method.
     */
    protected function isRefreshable(): bool
    {
        return str_contains($this->originalView, 'refreshComponent');
    }

    /**
     * Check if the view needs the SpladeBridge.
     */
    protected function needsSpladeBridge(): bool
    {
        if (! empty($this->getBladeFunctions())) {
            return true;
        }

        if (! empty($this->getBladeProperties())) {
            return true;
        }

        if ($this->isRefreshable()) {
            return true;
        }

        return false;
    }

    /**
     * Check if the view uses element refs.
     */
    protected function viewUsesElementRefs(): bool
    {
        return preg_match('/ref="(\w+)"/', $this->originalView) > 0;
    }

    /**
     * Check if the view uses v-model.
     */
    protected function viewUsesVModel(): bool
    {
        return str_contains($this->originalView, 'modelValue');
    }

    /**
     * Extract the script from the view.
     */
    protected function splitOriginalView(): void
    {
        $this->originalScript = Str::betweenFirst($this->originalView, '<script setup>', '</script>');

        $this->viewWithoutScriptTag = Str::of($this->originalView)
            ->replaceFirst("<script setup>{$this->originalScript}</script>", '')
            ->trim()
            ->toString();
    }

    /**
     * Replace someMethod.loading with someMethod.loading.value
     */
    protected function replaceComponentMethodLoadingStates(string $script): string
    {
        $methods = ['refreshComponent', ...$this->getBladeFunctions()];

        return preg_replace_callback('/(\w+)\.loading/', function ($matches) use ($methods) {
            if (! in_array($matches[1], $methods)) {
                return $matches[0];
            }

            return $matches[1].'.loading.value';
        }, $script);
    }

    /**
     * Replace ref="textarea" with :ref="(value) => setRef('textarea', value)"
     */
    protected function replaceElementRefs(string $script): string
    {
        return preg_replace('/ref="(\w+)"/', ':ref="(value) => setSpladeRef(\'$1\', value)"', $script);
    }

    /**
     * Extract the defineProps from the script.
     *
     * @return array<string>
     */
    protected function extractDefinePropsFromScript(): array
    {
        $defaultProps = Collection::make([
            'spladeBridge' => 'Object',
            'spladeTemplateId' => 'String',
        ])->when($this->viewUsesVModel(), fn (Collection $collection) => $collection->put('modelValue', '{}'));

        $defineProps = $this->scriptParser->getDefineProps($defaultProps->all());

        if (! $defineProps['original']) {
            return [$this->originalScript, $defineProps['new']];
        }

        return [
            str_replace($defineProps['original'], $defineProps['new'], $this->originalScript),
            '',
        ];
    }

    /**
     * Renders the imports for the Vue script.
     */
    protected function renderImports(): string
    {
        $vueFunctionsImports = $this->scriptParser->getVueFunctions()
            ->push('h')
            ->when($this->needsSpladeBridge(), fn ($collection) => $collection->push('ref'))
            ->when($this->isRefreshable(), fn ($collection) => $collection->push('inject'))
            ->unless(empty($this->getBladeProperties()), fn ($collection) => $collection->push('computed'))
            ->unique()
            ->sort()
            ->implode(',');

        $spladeCoreImports = $this->needsSpladeBridge()
            ? 'BladeComponent, GenericSpladeComponent'
            : 'GenericSpladeComponent';

        return <<<JS
import { {$spladeCoreImports} } from '@protonemedia/laravel-splade-core'
import { {$vueFunctionsImports} } from 'vue';
JS;
    }

    /**
     * Renders the state for the SpladeBridge.
     */
    protected function renderSpladeBridgeState(): string
    {
        if (! $this->needsSpladeBridge()) {
            return '';
        }

        return <<<'JS'
const _spladeBridgeState = ref(props.spladeBridge);
JS;
    }

    /**
     * Renders a Javascript function that calls the Blade function.
     */
    protected function renderBladeFunctionsAsJavascriptFunctions(): string
    {
        $lines = [];

        foreach ($this->getBladeFunctions() as $function) {
            $lines[] = <<<JS
const {$function} = BladeComponent.asyncComponentMethod('{$function}', _spladeBridgeState);
JS;
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Renders a computed Vue property for a Blade property.
     */
    protected static function renderBladePropertyAsComputedVueProperty(string $property): string
    {
        return <<<JS
const {$property} = computed({
    get() { return _spladeBridgeState.value.data.{$property} },
    set(newValue) { _spladeBridgeState.value.data.{$property} = newValue }
});
JS;
    }

    /**
     * Renders computed Vue properties for all Blade properties.
     */
    protected function renderBladePropertiesAsComputedVueProperties(): string
    {
        $lines = [];

        foreach ($this->getBladeProperties() as $property) {
            $lines[] = static::renderBladePropertyAsComputedVueProperty($property);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Injects the $spladeTemplateBus and adds a 'refreshComponent' method.
     */
    protected function renderJavascriptFunctionToRefreshComponent(): string
    {
        if (! $this->isRefreshable()) {
            return '';
        }

        return <<<'JS'
const _spladeTemplateBus = inject("$spladeTemplateBus");
const refreshComponent = BladeComponent.asyncRefreshComponent(_spladeBridgeState, _spladeTemplateBus);
JS;
    }

    /**
     * Renders the element ref store and setter.
     */
    protected function renderElementRefStoreAndSetter(): string
    {
        if (! $this->viewUsesElementRefs()) {
            return '';
        }

        return <<<'JS'
const $refs = {};
const setSpladeRef = (key, value) => $refs[key] = value;
JS;
    }

    /**
     * Renders the SpladeRender 'h'-function.
     */
    protected function renderSpladeRenderFunction(): string
    {
        $inheritAttrs = $this->attributesAreCustomBound() ? <<<'JS'
inheritAttrs: false,
JS : '';

        $dataObject = Collection::make($this->getBladeProperties())
            ->merge($this->scriptParser->getVariables())
            ->merge($this->getBladeFunctions())
            ->when($this->isRefreshable(), fn (Collection $collection) => $collection->push('refreshComponent'))
            ->when($this->viewUsesElementRefs(), fn (Collection $collection) => $collection->push('setSpladeRef'))
            ->implode(',');

        return <<<JS
const spladeRender = h({
    {$inheritAttrs}
    name: "{$this->getTag()}Render",
    components: {GenericSpladeComponent},
    template: spladeTemplates[props.spladeTemplateId],
    data: () => { return { {$dataObject} } }
});
JS;
    }
}