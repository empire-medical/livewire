<?php

namespace Livewire;

use WeakMap;
use Livewire\Drawer\Utils;

class ComponentHookRegistry
{
    protected static $components;

    protected static $componentHooks = [];

    // hook class => [event => true] for the lifecycle methods that class actually implements...
    protected static array $hookEvents = [];

    protected const EVENTS = [
        'boot', 'mount', 'hydrate', 'update', 'call', 'render', 'renderIsland',
        'renderPlaceholder', 'dehydrate', 'destroy', 'exception',
    ];

    protected static function implementedEvents($hook): array
    {
        $class = is_object($hook) ? $hook::class : $hook;

        if (isset(static::$hookEvents[$class])) return static::$hookEvents[$class];

        $events = [];

        foreach (static::EVENTS as $event) {
            if (method_exists($class, $event)) $events[$event] = true;
        }

        return static::$hookEvents[$class] = $events;
    }

    static function register($hook)
    {
        if (method_exists($hook, 'provide')) $hook::provide();

        if (in_array($hook, static::$componentHooks)) return;

        static::$componentHooks[] = $hook;
    }

    static function getHook($component, $hook)
    {
        if (! isset(static::$components[$component])) return;

        $componentHooks = static::$components[$component];

        foreach ($componentHooks as $componentHook) {
            if ($componentHook instanceof $hook) return $componentHook;
        }
    }

    static function boot()
    {
        static::$components = new WeakMap;

        static::$hookEvents = [];

        foreach (static::$componentHooks as $hook) {
            $events = static::implementedEvents($hook);

            // Every hook class still gets an instance per component (even one implementing no lifecycle
            // methods), because other code can look instances up with getHook() and call their methods...
            on('mount', function ($component, $params, $key, $parent, $attributes) use ($hook, $events) {
                if (! $instance = static::initializeHook($hook, $component)) {
                    return;
                }

                if (isset($events['boot'])) $instance->callBoot();
                if (isset($events['mount'])) $instance->callMount($params, $parent, $attributes);
            });

            on('hydrate', function ($component, $memo) use ($hook, $events) {
                if (! $instance = static::initializeHook($hook, $component)) {
                    return;
                }

                if (isset($events['boot'])) $instance->callBoot();
                if (isset($events['hydrate'])) $instance->callHydrate($memo);
            });
        }

        on('update', function ($component, $fullPath, $newValue) {
            $propertyName = Utils::beforeFirstDot($fullPath);

            return static::proxyCallToHooks($component, 'callUpdate')($propertyName, $fullPath, $newValue);
        });

        on('call', function ($component, $method, $params, $componentContext, $earlyReturn, $metadata) {
            return static::proxyCallToHooks($component, 'callCall')($method, $params, $earlyReturn, $metadata, $componentContext);
        });

        on('render', function ($component, $view, $data) {
            return static::proxyCallToHooks($component, 'callRender')($view, $data);
        });

        on('renderIsland', function ($component, $name, $view, $data) {
            return static::proxyCallToHooks($component, 'callRenderIsland')($name, $view, $data);
        });

        on('render.placeholder', function ($component, $view, $data) {
            return static::proxyCallToHooks($component, 'callRenderPlaceholder')($view, $data);
        });

        on('dehydrate', function ($component, $context) {
            static::proxyCallToHooks($component, 'callDehydrate')($context);
        });

        on('destroy', function ($component, $context) {
            static::proxyCallToHooks($component, 'callDestroy')($context);
        });

        on('exception', function ($target, $e, $stopPropagation) {
            if ($target instanceof \Livewire\Features\SupportFormObjects\Form) {
                $target = $target->getComponent();
            }

            if ($target instanceof \Livewire\Component) {
                static::proxyCallToHooks($target, 'callException')($e, $stopPropagation);
            }
        });
    }

    static public function initializeHook($hook, $target)
    {
        if (! isset(static::$components[$target])) static::$components[$target] = [];

        $hook = new $hook;

        $hook->setComponent($target);

        // If no `skip` method has been implemented, then boot the hook anyway
        if (method_exists($hook, 'skip') && $hook->skip()) {
            return;
        }

        static::$components[$target][] = $hook;

        return $hook;
    }

    static function proxyCallToHooks($target, $method) {
        // "callRenderIsland" => "renderIsland"...
        $event = lcfirst(substr($method, 4));

        return function (...$params) use ($target, $method, $event) {
            $forwardCallbacks = [];

            foreach (static::$components[$target] ?? [] as $hook) {
                // Don't dispatch to hooks that don't implement this event (their call* wrapper is a no-op)...
                if (! isset(static::implementedEvents($hook)[$event])) continue;

                if ($callback = $hook->{$method}(...$params)) {
                    $forwardCallbacks[] = $callback;
                }
            }

            // Nothing to run on the way out, so don't allocate a closure for the event bus to call...
            if ($forwardCallbacks === []) return null;

            return function (...$forwards) use ($forwardCallbacks) {
                foreach ($forwardCallbacks as $callback) {
                    $callback(...$forwards);
                }
            };
        };
    }
}
