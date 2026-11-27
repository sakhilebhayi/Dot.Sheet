<x-action-section id="emails">
    <x-slot name="title">
        {{ __('Emails') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Which emails you get. Everything still shows under the bell at the top of the page.') }}
    </x-slot>

    <x-slot name="content">
        <form wire:submit="save">
            <fieldset class="entries">
                <legend class="hint">{{ __('Security and account emails, such as a changed password or a deleted account, are always sent.') }}</legend>
                @foreach ($options as $category => $label)
                    <label class="side" for="email-{{ $category }}">
                        <x-checkbox id="email-{{ $category }}" wire:model="email.{{ $category }}" />
                        <span>{{ __($label) }}</span>
                    </label>
                @endforeach
            </fieldset>

            <div class="deeds">
                <x-button type="submit" wire:loading.attr="disabled">{{ __('Save') }}</x-button>
                <x-action-message on="saved">{{ __('Saved.') }}</x-action-message>
            </div>
        </form>
    </x-slot>
</x-action-section>
