<div x-data="{
    directory: '', entries: [], preview: '', manifest: [], approved: false,
    secret: '', tokenMode: 'raw',
    draft: {id:'',source:'',destination:'',format:'text',mode:'raw',content:'',digest:'',format_confirmed:false,readonly_confirmed:false,migration_confirmed:false},
    invalidate() { this.preview = ''; this.manifest = []; this.approved = false },
    insert() {
        if (!this.secret) return;
        const source = this.$refs.sourceText;
        const bytes = text => new TextEncoder().encode(text).length;
        this.$wire.insertTemplateToken(source.value, bytes(source.value.slice(0, source.selectionStart)),
            bytes(source.value.slice(0, source.selectionEnd)), this.secret, this.tokenMode, this.draft.format)
    }
}" x-on:bps-template-directory.window="directory = $event.detail.listing.directory; entries = $event.detail.listing.entries"
    x-on:bps-open-template.window="draft = $event.detail.draft; tokenMode = draft.mode; invalidate(); $refs.template.showModal()"
    x-on:bps-template-inserted.window="draft.content = $event.detail.source; invalidate()"
    x-on:bps-template-preview.window="preview = $event.detail.preview; manifest = $event.detail.manifest; approved = false"
    x-on:bps-template-saved.window="$refs.template.close()">
    <p>Choose an existing file or enter a path to create one. Paths are relative to the server's editable data directory.</p>
    <div style="display:grid;gap:.5rem;margin:.75rem 0">
        <label>Source path
            <x-filament::input.wrapper><x-filament::input x-ref="path" placeholder="csgo/addons/s2script/configs/plugin-config.json" /></x-filament::input.wrapper>
        </label>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            <x-filament::button color="gray" type="button" x-on:click="$wire.openTemplate($refs.path.value)">Open or create file template</x-filament::button>
            @if ($canBrowseFiles)<x-filament::button color="gray" type="button" wire:click="browseTemplates('')">Browse files</x-filament::button>@endif
        </div>
    </div>
    <div x-show="entries.length" style="border:1px solid #64748b40;border-radius:.5rem;padding:.75rem;max-height:15rem;overflow:auto">
        <p>Directory: <span x-text="directory || '/'"></span> · First 500 entries</p>
        <button type="button" x-on:click="$wire.browseTemplates(directory.split('/').slice(0,-1).join('/'))" style="text-decoration:underline">Parent directory</button>
        <template x-for="entry in entries" :key="entry.path">
            <div><button type="button" x-on:click="entry.directory ? $wire.browseTemplates(entry.path) : entry.file && $wire.openTemplate(entry.path)" style="text-decoration:underline">
                <span x-text="(entry.directory ? 'Folder: ' : '') + entry.name"></span>
            </button></div>
        </template>
    </div>
    <dialog wire:ignore.self x-ref="template" x-on:close="draft.content = ''; preview = ''; manifest = []; approved = false"
        style="width:95%;max-width:65rem;max-height:95vh;overflow:auto;border-radius:.75rem;padding:1.5rem;color:inherit;background:Canvas">
        <h2 style="font-size:1.25rem;font-weight:600">Configure file template</h2>
        <p>{{ __('better-pelican-secrets::secrets.readonly') }}</p>
        <p>Default mapping is <code>/home/container</code>. Confirm the destination matches the path your game reads. Wings validates destinations again at start.</p>
        @if ($feedback)<p role="status" style="margin:.75rem 0">{{ $feedback }}</p>@endif
        <div style="display:grid;gap:.75rem;margin:1rem 0">
            <label>Editable source path
                <x-filament::input.wrapper><x-filament::input x-model="draft.source" x-on:input="invalidate()" /></x-filament::input.wrapper>
            </label>
            <label>Read-only runtime destination
                <x-filament::input.wrapper><x-filament::input x-model="draft.destination" x-on:input="invalidate()" /></x-filament::input.wrapper>
            </label>
            <label>File format (confirm the suggestion)
                <x-filament::input.wrapper><x-filament::input.select x-model="draft.format" x-on:change="draft.format_confirmed = false; invalidate()">
                    <option value="json">JSON</option><option value="yaml">YAML</option><option value="dotenv">dotenv (.env)</option><option value="text">Other text</option>
                </x-filament::input.select></x-filament::input.wrapper>
            </label>
            <label>Default token mode
                <x-filament::input.wrapper><x-filament::input.select x-model="draft.mode" x-on:change="draft.format_confirmed = false; invalidate()">
                    <option value="raw">raw</option><option value="json-string">json-string</option><option value="yaml-string">yaml-string</option><option value="dotenv">dotenv</option>
                </x-filament::input.select></x-filament::input.wrapper>
            </label>
            <label><input type="checkbox" x-model="draft.format_confirmed"> I confirm this format and default escaping mode.</label>
            <label>Template source
                <textarea x-ref="sourceText" x-model="draft.content" x-on:input="invalidate()" rows="12" spellcheck="false" autocomplete="off"
                    style="display:block;width:100%;padding:.75rem;border:1px solid #64748b80;border-radius:.5rem;background:transparent;font-family:monospace"></textarea>
            </label>
            <p>Use <code>@{{secret:NAME}}</code> or <code>@{{secret:NAME|mode}}</code>. Escape a literal marker as <code>@{{!secret:NAME}}</code>.</p>
            <p>For JSON strings, select the value's contents or place the cursor inside it. The picker replaces the entire quoted value. Raw mode is for deliberate unescaped content.</p>
            <label>Secret to insert
                <x-filament::input.wrapper><x-filament::input.select x-model="secret">
                    <option value="">Select a bindable secret</option>
                    @foreach ($bindable as $item)<option value="{{ $item['id'] }}">{{ $item['name'] }}</option>@endforeach
                </x-filament::input.select></x-filament::input.wrapper>
            </label>
            <label>Mode for this token
                <x-filament::input.wrapper><x-filament::input.select x-model="tokenMode">
                    <option value="raw">raw</option><option value="json-string">json-string</option><option value="yaml-string">yaml-string</option><option value="dotenv">dotenv</option>
                </x-filament::input.select></x-filament::input.wrapper>
            </label>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                <x-filament::button type="button" color="gray" x-on:click="insert()">Insert at selection</x-filament::button>
                <x-filament::button type="button" color="gray" x-on:click="$wire.previewTemplate(draft)">Preview with dummy values</x-filament::button>
            </div>
            <div x-show="manifest.length">
                <h3 style="font-weight:600">Dummy preview</h3>
                <pre x-text="preview" style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:16rem;overflow:auto"></pre>
                <h3 style="font-weight:600">Approved references</h3>
                <template x-for="reference in manifest" :key="reference.secret_id + reference.mode">
                    <p><span x-text="reference.name"></span> · <span x-text="reference.mode"></span> · <span x-text="reference.count"></span> occurrence(s)</p>
                </template>
                <label><input type="checkbox" x-model="approved"> I approve these references and occurrence counts.</label>
            </div>
            <label><input type="checkbox" x-model="draft.readonly_confirmed"> The game can use this configuration as a read-only runtime file.</label>
            <label><input type="checkbox" x-model="draft.migration_confirmed"> Replace/create this source with markers. Old copies, logs, and backups remain unchanged; exposed credentials may need rotation.</label>
            <p>Saving does not restart the server. New values and bindings apply on the next normal start or restart.</p>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                <x-filament::button type="button" x-on:click="$wire.saveTemplate({...draft,references:approved ? manifest.map(entry => ({secret_id:entry.secret_id,mode:entry.mode})) : []})">Save source and approve binding</x-filament::button>
                <x-filament::button type="button" color="gray" x-on:click="$refs.template.close()">Cancel and clear</x-filament::button>
            </div>
        </div>
    </dialog>
</div>
