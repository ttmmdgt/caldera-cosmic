<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout("layouts.app")] class extends Component {
    //
}; ?>

<x-slot name="title">{{ __("Data - IP Blending Monitoring") }}</x-slot>

<x-slot name="header">
    <x-nav-insights-ibms></x-nav-insights-ibms>
</x-slot>
<div>
    //
</div>
