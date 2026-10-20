@php
    $variable = $getRecord();
    $indicator = $variable instanceof \App\Models\ServerVariable
        ? app(\ShookEagle\BetterPelicanSecrets\Filament\Shared\StartupBindingActions::class)->indicator($variable) : null;
@endphp
@if ($indicator !== null)
    <x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
        <p role="note" style="padding:.75rem;border:1px solid #64748b60;border-radius:.5rem">{{ $indicator }}</p>
        <p>Manage this binding on the Secrets page. Changes apply on the next normal start or restart.</p>
    </x-dynamic-component>
@else
    @include('filament.components.startup-variable')
@endif
