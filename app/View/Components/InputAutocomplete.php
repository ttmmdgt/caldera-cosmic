<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class InputAutocomplete extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public ?string $placeholder = '',
        public ?string $searchRoute = null,
        public ?string $searchUrl = null,
        public int $minChars = 1,
        public int $debounce = 300,
    ) {
        //
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.input-autocomplete');
    }
}
