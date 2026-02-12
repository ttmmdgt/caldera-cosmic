@props(['placeholder' => '', 'searchRoute' => null, 'searchUrl' => null, 'minChars' => 1, 'debounce' => 300, 'disabled' => false])

<div 
    x-data="{
        open: false,
        search: @entangle($attributes->wire('model')),
        suggestions: [],
        selectedIndex: -1,
        loading: false,
        debounceTimer: null,
        
        init() {
            this.$watch('search', value => {
                this.selectedIndex = -1;
                if (value && value.length >= {{ $minChars }}) {
                    this.debouncedSearch(value);
                } else {
                    this.suggestions = [];
                    this.open = false;
                }
            });
        },
        
        debouncedSearch(value) {
            clearTimeout(this.debounceTimer);
            this.debounceTimer = setTimeout(() => {
                this.fetchSuggestions(value);
            }, {{ $debounce }});
        },
        
        async fetchSuggestions(query) {
            this.loading = true;
            try {
                const url = @if($searchRoute) `{{ route($searchRoute) }}?q=${encodeURIComponent(query)}` @elseif($searchUrl) `{{ $searchUrl }}?q=${encodeURIComponent(query)}` @else null @endif;
                
                if (!url) {
                    console.error('No search route or URL provided');
                    return;
                }
                
                const response = await fetch(url);
                const data = await response.json();
                this.suggestions = data.suggestions || data || [];
                this.open = this.suggestions.length > 0;
            } catch (error) {
                console.error('Error fetching suggestions:', error);
                this.suggestions = [];
                this.open = false;
            } finally {
                this.loading = false;
            }
        },
        
        selectSuggestion(suggestion) {
            this.search = typeof suggestion === 'string' ? suggestion : (suggestion.name || suggestion.value || suggestion.label);
            this.open = false;
            this.suggestions = [];
            this.$refs.input.focus();
        },
        
        navigateDown() {
            if (this.selectedIndex < this.suggestions.length - 1) {
                this.selectedIndex++;
                this.scrollToSelected();
            }
        },
        
        navigateUp() {
            if (this.selectedIndex > 0) {
                this.selectedIndex--;
                this.scrollToSelected();
            }
        },
        
        selectCurrent() {
            if (this.selectedIndex >= 0 && this.selectedIndex < this.suggestions.length) {
                this.selectSuggestion(this.suggestions[this.selectedIndex]);
            }
        },
        
        scrollToSelected() {
            this.$nextTick(() => {
                const selected = this.$refs.dropdown?.querySelector('[data-selected=true]');
                if (selected) {
                    selected.scrollIntoView({ block: 'nearest' });
                }
            });
        }
    }"
    @click.away="open = false"
    class="relative"
>
    <div class="relative">
        <input 
            x-ref="input"
            type="text"
            x-model="search"
            @keydown.down.prevent="navigateDown()"
            @keydown.up.prevent="navigateUp()"
            @keydown.enter.prevent="selectCurrent()"
            @keydown.escape="open = false"
            @focus="search && search.length >= {{ $minChars }} && suggestions.length > 0 ? open = true : null"
            placeholder="{{ $placeholder }}"
            {{ $disabled ? 'disabled' : '' }}
            {!! $attributes->merge(['class' => 'w-full border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600 rounded-md shadow-sm pr-10']) !!}
        >
        
        <!-- Loading spinner -->
        <div x-show="loading" class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
            <svg class="w-5 h-5 text-neutral-400 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        </div>
    </div>
    
    <!-- Suggestions dropdown -->
    <div 
        x-show="open && suggestions.length > 0"
        x-ref="dropdown"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        class="absolute z-50 w-full mt-1 bg-white dark:bg-neutral-800 border border-neutral-300 dark:border-neutral-700 rounded-md shadow-lg max-h-60 overflow-auto"
        x-cloak
    >
        <ul>
            <template x-for="(suggestion, index) in suggestions" :key="index">
                <li 
                    @click="selectSuggestion(suggestion)"
                    :data-selected="index === selectedIndex"
                    :class="{
                        'bg-caldy-500 text-white': index === selectedIndex,
                        'text-neutral-900 dark:text-neutral-100 hover:bg-neutral-100 dark:hover:bg-neutral-700': index !== selectedIndex
                    }"
                    class="px-4 py-2 cursor-pointer transition-colors"
                >
                    <span x-text="typeof suggestion === 'string' ? suggestion : (suggestion.name || suggestion.value || suggestion.label)"></span>
                </li>
            </template>
        </ul>
    </div>
</div>