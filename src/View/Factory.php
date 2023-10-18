<?php

namespace ProtoneMedia\SpladeCore\View;

use App\View\Components\Layout;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Illuminate\View\Component;
use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\Factory as BaseFactory;

class Factory extends BaseFactory
{
    protected static bool $trackSpladeComponents = false;

    protected static array $spladeComponents = [];

    protected static array $beforeStartComponentCallbacks = [];

    public static function trackSpladeComponents(): void
    {
        static::$trackSpladeComponents = true;
        static::clearSpladeComponents();
    }

    public static function clearSpladeComponents(): void
    {
        static::$spladeComponents = [];
    }

    public static function getSpladeComponent(string $key): ?string
    {
        return static::$spladeComponents[$key] ?? null;
    }

    /**
     * Register a callback to be called before the component is started.
     */
    public static function beforeStartComponent(callable $callback): void
    {
        static::$beforeStartComponentCallbacks[] = $callback;
    }

    /**
     * Execute the callback before the component is started.
     */
    public function startComponent($view, array $data = [], $component = null)
    {
        if ($component instanceof Layout) {
            return parent::startComponent($view, $data);
        }

        if ($component instanceof Component && ! empty(static::$beforeStartComponentCallbacks)) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);

            // prevent leaking the full path
            $path = Str::after($trace[0]['file'], base_path());

            $hash = md5($path.'.'.$trace[0]['line']);

            foreach (static::$beforeStartComponentCallbacks as $callback) {
                $callback = $callback->bindTo($this, static::class);
                $callback($component, $data, $hash, $view);
            }
        }

        return parent::startComponent($view, $data);
    }

    public function renderComponent()
    {
        /** @var array */
        $componentData = $this->componentData[$this->currentComponent()];

        if (! array_key_exists('spladeBridge', $componentData)) {
            return parent::renderComponent();
        }

        $attributes = $componentData['attributes'];

        $this->componentData[$this->currentComponent()]['attributes'] = new ComponentAttributeBag;

        $output = parent::renderComponent();

        $templateId = $componentData['spladeBridge']['template_hash'];

        if (static::$trackSpladeComponents) {
            static::$spladeComponents[$templateId] = $output;
        }

        if (! $this->hasRenderedOnce('splade-templates')) {
            $this->markAsRenderedOnce('splade-templates');
            $this->extendPush('splade-templates', 'const spladeTemplates = {};');
        }

        $template = Js::from($output)->toHtml();

        $this->extendPush(
            'splade-templates',
            "spladeTemplates['{$templateId}'] = {$template};"
        );

        $spladeBridge = Js::from($componentData['spladeBridge'])->toHtml();

        $attrs = $attributes->toHtml();

        return static::$trackSpladeComponents
            ? "<!--splade-template-id=\"{$templateId}\"--><generic-splade-component {$attrs} :bridge=\"{$spladeBridge}\"></generic-splade-component>"
            : "<generic-splade-component {$attrs} :bridge=\"{$spladeBridge}\"></generic-splade-component>";
    }
}