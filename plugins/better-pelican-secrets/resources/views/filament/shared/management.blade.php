<div style="display:grid;gap:1.5rem" x-data="{ revealed: '', access: {users:[],delegable:[]}, scope: null, recipient: '', operations: [] }"
    @click.capture="if (!$refs.reveal.contains($event.target)) revealed = ''" @change.capture="revealed = ''"
    @bps-reveal.window="revealed = $event.detail.value; $refs.reveal.showModal(); setTimeout(() => { revealed = ''; $refs.reveal.close() }, 30000)"
    @bps-access.window="access = $event.detail.details; scope = $event.detail.scope; operations = []; recipient = ''; if (!$refs.access.open) $refs.access.showModal()"
    x-on:livewire:navigating.window="revealed = ''; $refs.reveal.close(); $refs.access.close()">
    <x-filament::section heading="Application status">
        <p><strong>{{ $server->name }}</strong> · Desired {{ $status->desiredRevision }} · Applied {{ $status->appliedRevision ?? 'Never applied' }}</p>
        <p>{{ $status->pending ? __('better-pelican-secrets::secrets.pending') : ($status->appliedRevision === null ? 'No runtime secret bindings are configured.' : 'Saved configuration is applied.') }}</p>
        @if ($status->dirty)<p>Source files have changed since the last successful start.</p>@endif
        @if ($status->lastErrorCode)<p>Configuration status: {{ $status->lastErrorCode }}</p>@endif
        <p style="margin-top:.75rem">{{ __('better-pelican-secrets::secrets.runtime_note') }}</p>
    </x-filament::section>

    @if ($feedback)<p role="status">{{ $feedback }}</p>@endif

    <x-filament::section heading="Secrets" description="Values stay hidden until you explicitly reveal one. Access is granted separately for each operation.">
        @if ($canManageServerAccess)
            <x-filament::button color="gray" wire:click="accessDetails(null)">Manage server access</x-filament::button>
        @endif
        @if ($canCreate)
            <details style="margin:1rem 0">
                <summary style="cursor:pointer;font-weight:600">Create secret</summary>
            <form x-data @submit.prevent="$wire.createSecret($refs.name.value, $refs.description.value, $refs.newValue.value, $refs.mode.value); $refs.newValue.value = ''" style="display:grid;gap:.75rem;margin:1rem 0">
                <label>Name (A–Z, digits, underscores)
                    <x-filament::input.wrapper><x-filament::input x-ref="name" required maxlength="64" pattern="[A-Z][A-Z0-9_]{0,63}" autocomplete="off" /></x-filament::input.wrapper>
                </label>
                <label>Description
                    <x-filament::input.wrapper><x-filament::input x-ref="description" maxlength="4096" autocomplete="off" /></x-filament::input.wrapper>
                </label>
                <label>Value source
                    <x-filament::input.wrapper><x-filament::input.select x-ref="mode">
                        <option value="manual">Enter a value</option><option value="generate">Generate random value</option><option value="empty">Explicitly empty value</option>
                    </x-filament::input.select></x-filament::input.wrapper>
                </label>
                <label>New value
                    <x-filament::input.wrapper><x-filament::input x-ref="newValue" type="password" autocomplete="new-password" /></x-filament::input.wrapper>
                </label>
                <div><x-filament::button type="submit" wire:loading.attr="disabled">Create secret</x-filament::button></div>
            </form>
            </details>
        @endif
        @forelse ($rows as $row)
            <article wire:key="secret-{{ $row['id'] }}" style="padding:1.25rem 0;border-top:1px solid #64748b40;display:grid;gap:.75rem">
                <h3 style="font-size:1.1rem;font-weight:600">{{ $row['name'] }}</h3>
                <p>{{ $row['description'] }}</p>
                <p>Value set · Version {{ $row['version'] }} · {{ $row['binding_count'] }} bindings · Updated {{ $row['updated_at'] }}</p>
                @if (in_array('update', $row['allowed'], true))
                    <details>
                        <summary style="cursor:pointer;font-weight:600">Edit value or description</summary>
                    <form x-data @submit.prevent="$wire.replaceValue(@js($row['id']), $refs.replacement.value); $refs.replacement.value = ''" style="display:grid;gap:.5rem">
                        <label>Replace value
                            <x-filament::input.wrapper><x-filament::input x-ref="replacement" type="password" autocomplete="new-password" placeholder="New value" /></x-filament::input.wrapper>
                        </label>
                        <p>{{ __('better-pelican-secrets::secrets.blank_preserves') }}</p>
                        <div><x-filament::button type="submit" color="gray">Replace</x-filament::button></div>
                    </form>
                    <form x-data @submit.prevent="$wire.saveDescription(@js($row['id']), $refs.description.value)" style="display:grid;gap:.5rem">
                        <label>Description
                            <x-filament::input.wrapper><x-filament::input x-ref="description" value="{{ $row['description'] }}" maxlength="4096" /></x-filament::input.wrapper>
                        </label>
                        <div><x-filament::button type="submit" color="gray">Save description</x-filament::button></div>
                    </form>
                    </details>
                @endif
                <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                    @if (in_array('reveal', $row['allowed'], true))
                        <x-filament::button color="gray" wire:click="revealValue('{{ $row['id'] }}')" wire:confirm="Reveal this value? This action is audited.">Reveal</x-filament::button>
                    @endif
                    @if (in_array('update', $row['allowed'], true))
                        <x-filament::button color="gray" wire:click="generateValue('{{ $row['id'] }}')" wire:confirm="Replace with a new random value on the next start?">Generate</x-filament::button>
                        <x-filament::button color="warning" wire:click="setEmptyValue('{{ $row['id'] }}')" wire:confirm="Set an intentionally empty value on the next start?">Set empty value</x-filament::button>
                    @endif
                    @if (in_array('manage-access', $row['allowed'], true))
                        <x-filament::button color="gray" wire:click="accessDetails('{{ $row['id'] }}')">Manage access</x-filament::button>
                    @endif
                    @if (in_array('delete', $row['allowed'], true))
                        <x-filament::button color="danger" wire:click="deleteSecret('{{ $row['id'] }}')" wire:confirm="Delete this secret? Active bindings must be removed first.">Delete</x-filament::button>
                    @endif
                </div>
            </article>
        @empty
            <p style="margin-top:1rem">No secret metadata is visible with your current permissions.</p>
        @endforelse
    </x-filament::section>

    <x-filament::section heading="File templates">
        <p>{{ __('better-pelican-secrets::secrets.readonly') }}</p>
        @if ($canSetupFiles && $bindable)
            @include('better-pelican-secrets::filament.shared.template-editor')
        @endif
        @foreach ($setupFailures as $failure)
            <p>Incomplete setup: <code>{{ $failure->source ?? $failure->file_id }}</code>. Reopen and review this source before retrying.</p>
        @endforeach
        @forelse ($files as $file)
            <p style="margin-top:.75rem"><code>{{ $file['source'] }}</code> → <code>{{ $file['destination'] }}</code> · {{ $file['format'] }}</p>
            @if ($canSetupFiles && $bindable && $file['can_manage'])
                <div style="display:flex;gap:.5rem;margin:.5rem 0;flex-wrap:wrap">
                    <x-filament::button color="gray" wire:click="openTemplate({{ \Illuminate\Support\Js::from($file['source']) }})">Edit template</x-filament::button>
                    <x-filament::button color="warning" wire:click="removeTemplate('{{ $file['id'] }}')" wire:confirm="Remove this managed binding on the next start? The source keeps its markers.">Remove binding</x-filament::button>
                </div>
            @endif
        @empty
            <p>No authorized file templates.</p>
        @endforelse
    </x-filament::section>

    <x-filament::section heading="Startup bindings">
        @if ($canSetupStartup && $bindable)
            <form x-data="{env:'',secret:'',confirmed:false}" x-on:submit.prevent="if(env && secret) $wire.bindStartup(env,secret,JSON.parse($refs.variable.options[$refs.variable.selectedIndex].dataset.metadata).digest,confirmed)" style="display:grid;gap:.75rem;margin:1rem 0">
                <label>Existing egg variable
                    <x-filament::input.wrapper><x-filament::input.select x-ref="variable" x-model="env" x-on:change="confirmed=false">
                        <option value="">Select a startup variable</option>
                        @foreach ($startupVariables as $variable)
                            <option value="{{ $variable['env'] }}" data-metadata="{{ json_encode($variable) }}" @disabled(!$variable['can_bind'])>{{ $variable['name'] }} ({{ $variable['env'] }}){{ $variable['bound'] ? ' — bound' : ($variable['migration_required'] ? ' — migration required' : '') }}</option>
                        @endforeach
                    </x-filament::input.select></x-filament::input.wrapper>
                </label>
                <label>Secret
                    <x-filament::input.wrapper><x-filament::input.select x-model="secret">
                        <option value="">Select a bindable secret</option>
                        @foreach ($bindable as $item)<option value="{{ $item['id'] }}">{{ $item['name'] }}</option>@endforeach
                    </x-filament::input.select></x-filament::input.wrapper>
                </label>
                <label><input type="checkbox" x-model="confirmed"> Clear the ordinary variable value and bind this secret. Old copies, logs, defaults, and backups are unchanged; exposed credentials may need rotation.</label>
                <div><x-filament::button type="submit">Confirm and bind for next start</x-filament::button></div>
            </form>
        @endif
        @forelse ($bindings as $binding)
            <p><code>{{ $binding['env'] }}</code> → {{ collect($rows)->firstWhere('id', $binding['secret_id'])['name'] }}</p>
            @if ($canSetupStartup && in_array($binding['secret_id'], array_column($bindable, 'id'), true))
                <x-filament::button color="warning" wire:click="unbindStartup({{ \Illuminate\Support\Js::from($binding['env']) }})" wire:confirm="Remove this startup binding for the next start? The ordinary value remains empty.">Unbind</x-filament::button>
            @endif
        @empty
            <p>No authorized startup bindings.</p>
        @endforelse
    </x-filament::section>

    @if ($audit)
        <x-filament::section heading="Recent audit events" description="The latest 40 events for this server. Values are never recorded.">
            @foreach ($audit as $event)
                <p>{{ $event['created_at'] }} · Actor {{ ($event['metadata']['origin'] ?? null) === 'host' ? 'Host/system' : ($event['actor_id'] ?? 'Deleted user') }} · {{ $event['operation'] }} · <code>{{ json_encode($event['metadata'], JSON_UNESCAPED_SLASHES) }}</code></p>
            @endforeach
        </x-filament::section>
    @endif

    <dialog wire:ignore.self x-ref="reveal" @close="revealed = ''" @cancel="revealed = ''" style="max-width:40rem;width:90%;border-radius:.75rem;padding:1.5rem;color:inherit;background:Canvas">
        <h2 style="font-size:1.2rem;font-weight:600">Revealed value</h2>
        <p>Clears on close, navigation, or after 30 seconds.</p>
        <pre x-text="revealed" style="white-space:pre-wrap;overflow-wrap:anywhere;margin:1rem 0;max-height:50vh;overflow:auto"></pre>
        <x-filament::button type="button" x-on:click="revealed = ''; $refs.reveal.close()">Close and clear</x-filament::button>
    </dialog>

    <dialog wire:ignore.self x-ref="access" style="max-width:55rem;width:95%;border-radius:.75rem;padding:1.5rem;color:inherit;background:Canvas">
        <h2 style="font-size:1.2rem;font-weight:600">Manage access</h2>
        <p>Grants add access. Native roles and broader grants are inherited; remove those at their original scope.</p>
        <div style="overflow:auto;max-height:35vh;margin:1rem 0">
            <table style="width:100%;text-align:left">
                <thead><tr><th>User</th><th>Direct</th><th>Inherited</th><th>Native / root</th></tr></thead>
                <tbody><template x-for="user in access.users" :key="user.user_id">
                    <tr><td x-text="user.username"></td><td x-text="user.direct.join(', ') || '—'"></td><td x-text="user.inherited.join(', ') || '—'"></td><td x-text="user.root ? 'Root (trusted)' : user.native.join(', ') || '—'"></td></tr>
                </template></tbody>
            </table>
        </div>
        <label>Server member
            <x-filament::input.wrapper><x-filament::input.select x-model="recipient">
                <option value="">Select a member</option>
                <template x-for="user in access.users.filter(user => user.member)" :key="user.user_id"><option :value="user.user_id" x-text="user.username"></option></template>
            </x-filament::input.select></x-filament::input.wrapper>
        </label>
        <div style="display:flex;gap:1rem;flex-wrap:wrap;margin:1rem 0">
            <template x-for="operation in access.delegable" :key="operation">
                <label><input type="checkbox" :value="operation" x-model="operations"> <span x-text="operation"></span></label>
            </template>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            <x-filament::button type="button" x-on:click="if(recipient && operations.length) $wire.changeAccess(scope, Number(recipient), operations, false)">Grant selected</x-filament::button>
            <x-filament::button type="button" color="warning" x-on:click="if(recipient && operations.length) $wire.changeAccess(scope, Number(recipient), operations, true)">Revoke selected</x-filament::button>
            <x-filament::button type="button" color="gray" x-on:click="$refs.access.close()">Close</x-filament::button>
        </div>
    </dialog>
</div>
