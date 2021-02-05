<?php

namespace Filament\Fields;

use Filament\View\Components\Form;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Tappable;

class Field
{
    use Tappable;

    public $context;

    public $enabled = true;

    public $fields = [];

    public $hooks = [];

    public $id;

    public $label;

    public $name;

    public $pendingExcludedContextModifications = [];

    public $pendingIncludedContextModifications = [];

    public $record;

    public $rules = [];

    protected $view;

    public function __construct($name = null)
    {
        $this->name = $name;

        if ($this->name === null) return;

        $this->id((string) Str::of($this->name)->slug());
        $this->label((string) Str::of($this->name)->afterLast('.')->kebab()->replace('-', ' ')->ucfirst());
        $this->rules(['nullable']);
    }

    public function addRules($rules)
    {
        collect($rules)
            ->each(function ($conditionsToAdd, $field) {
                if (! is_array($conditionsToAdd)) $conditionsToAdd = explode('|', $conditionsToAdd);

                $this->rules[$field] = collect($this->rules[$field] ?? [])
                    ->filter(function ($originalCondition) use ($conditionsToAdd) {
                        if (! is_string($originalCondition)) return true;

                        $conditionsToAdd = collect($conditionsToAdd);

                        if ($conditionsToAdd->contains($originalCondition)) return false;

                        if (! Str::of($originalCondition)->contains(':')) return true;

                        $originalConditionType = (string) Str::of($originalCondition)->before(':');

                        return ! $conditionsToAdd->contains(function ($conditionToAdd) use ($originalConditionType) {
                            return $originalConditionType === (string) Str::of($conditionToAdd)->before(':');
                        });
                    })
                    ->push(...$conditionsToAdd)
                    ->toArray();
            });

        return $this;
    }

    public function context($context)
    {
        $this->context = $context;

        if (array_key_exists($this->context, $this->pendingIncludedContextModifications)) {
            collect($this->pendingIncludedContextModifications[$this->context])
                ->each(fn($callback) => $callback($this));
        }

        $this->pendingIncludedContextModifications = [];

        collect($this->pendingExcludedContextModifications)
            ->filter(fn($callbacks, $context) => $context !== $this->context)
            ->each(function ($callbacks) {
                collect($callbacks)
                    ->each(fn($callback) => $callback($this));
            });

        $this->pendingExcludedContextModifications = [];

        return $this;
    }

    public function dd()
    {
        dd($this);

        return $this;
    }

    public function disable()
    {
        $this->enabled = false;

        return $this;
    }

    public function enable()
    {
        $this->enabled = true;

        return $this;
    }

    public function except($contexts, $callback = null)
    {
        if (! is_array($contexts)) $contexts = [$contexts];

        if (! $callback) {
            $this->disable();

            $callback = fn($field) => $field->enable();
        }

        if (! $this->context) {
            collect($contexts)
                ->each(function ($context) use ($callback) {
                    if (! array_key_exists($context, $this->pendingExcludedContextModifications)) {
                        $this->pendingExcludedContextModifications[$context] = [];
                    }

                    $this->pendingExcludedContextModifications[$context][] = $callback;
                });

            return $this;
        }

        if (in_array($this->context, $contexts)) return $this;

        $callback($this);

        return $this;
    }

    public function fields($fields)
    {
        $this->fields = $fields;

        return $this;
    }

    public function getForm()
    {
        return new Form($this->fields, $this->context, $this->record);
    }

    public function getHooks()
    {
        $hooks = $this->hooks;

        collect($this->getForm()->getHooks())
            ->each(function ($callbacks, $event) use (&$hooks) {
                $hooks[$event] = array_merge($hooks[$event] ?? [], $callbacks);
            });

        return $hooks;
    }

    public function getRules()
    {
        if (! $this->enabled) return [];

        $rules = $this->rules;

        collect($this->getForm()->getRules())
            ->each(function ($conditions, $field) use (&$rules) {
                $conditions = collect($conditions)
                    ->map(function ($condition) {
                        if (! is_string($condition)) return $condition;

                        return (string) Str::of($condition)->replace('{{record}}', $this->record ? $this->record->getKey() : '');
                    })
                    ->toArray();

                $rules[$field] = array_merge($rules[$field] ?? [], $conditions);
            });

        return $rules;
    }

    public function getValidationAttributes()
    {
        if (! $this->enabled) return [];

        $attributes = [];

        if ($this->name !== null && $this->label !== null) {
            $label = Str::of($this->label)->lower();

            $attributes[$this->name] = $label;
        }

        collect($this->getForm()->getValidationAttributes())
            ->each(function ($label, $name) use (&$attributes) {
                $attributes[$name] = $label;
            });

        return $attributes;
    }

    public function id($id)
    {
        $this->id = $id;

        return $this;
    }

    public function label($label)
    {
        $this->label = $label;

        return $this;
    }

    public function only($contexts, $callback = null)
    {
        if (! is_array($contexts)) $contexts = [$contexts];

        if (! $callback) {
            $this->disable();

            $callback = fn($field) => $field->enable();
        }

        if (! $this->context) {
            collect($contexts)
                ->each(function ($context) use ($callback) {
                    if (! array_key_exists($context, $this->pendingIncludedContextModifications)) {
                        $this->pendingIncludedContextModifications[$context] = [];
                    }

                    $this->pendingIncludedContextModifications[$context][] = $callback;
            });

            return $this;
        }

        if (! in_array($this->context, $contexts)) return $this;

        $callback($this);

        return $this;
    }

    public function record($record)
    {
        $this->record = $record;

        return $this;
    }

    public function registerHook($event, $callback)
    {
        if (! array_key_exists($event, $this->hooks)) $this->hooks[$event] = [];

        $this->hooks[$event][] = $callback;

        return $this;
    }

    public function removeRules($rules)
    {
        collect($rules)
            ->each(function ($conditionsToRemove, $field) {
                if (! is_array($conditionsToRemove)) $conditionsToRemove = explode('|', $conditionsToRemove);

                if (empty($conditionsToRemove)) {
                    unset($this->rules[$field]);

                    return;
                }

                $this->rules[$field] = collect($this->rules[$field] ?? [])
                    ->filter(function ($originalCondition) use ($conditionsToRemove) {
                        if (! is_string($originalCondition)) return true;

                        $conditionsToRemove = collect($conditionsToRemove);

                        if ($conditionsToRemove->contains($originalCondition)) return false;

                        if (! Str::of($originalCondition)->contains(':')) return true;

                        $originalConditionType = (string) Str::of($originalCondition)->before(':');

                        return ! $conditionsToRemove->contains(function ($conditionToRemove) use ($originalConditionType) {
                            return $originalConditionType === (string) Str::of($conditionToRemove)->before(':');
                        });
                    })
                    ->toArray();
            });

        return $this;
    }

    public function rules($conditions)
    {
        $this->rules = [$this->name => $conditions];

        return $this;
    }

    public function view($view)
    {
        $this->view = $view;

        return $this;
    }

    public function render()
    {
        if (! $this->enabled) return;

        $view = $this->view ?? 'filament::fields.' . Str::of(class_basename(static::class))->kebab();

        return view($view, ['field' => $this]);
    }
}
