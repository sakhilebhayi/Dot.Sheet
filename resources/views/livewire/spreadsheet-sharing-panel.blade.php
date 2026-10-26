<div class="space-y-3">
    <h3 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Sharing</h3>

    @if($statusMessage)
        <div class="text-xs rounded px-2 py-1 {{ $statusType === 'error' ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-200' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200' }}">
            {{ $statusMessage }}
        </div>
    @endif

    <div class="space-y-2 border border-gray-200 dark:border-gray-700 rounded-lg p-2">
        <div class="text-xs font-medium text-gray-700 dark:text-gray-300">Invite by Email</div>
        <input aria-label="person@example.com" type="email" wire:model.defer="inviteEmail" placeholder="person@example.com"
               class="w-full px-2 py-1.5 text-xs border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white" />

        <div class="grid grid-cols-2 gap-2">
            <select aria-label="Invitepermission" wire:model.defer="invitePermission"
                    class="px-2 py-1.5 text-xs border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                <option value="view">View</option>
                <option value="comment">Comment</option>
                <option value="edit">Edit</option>
                <option value="admin">Admin</option>
            </select>
            <input aria-label="Expiry" type="number" min="1" max="90" wire:model.defer="inviteExpiryDays"
                   class="px-2 py-1.5 text-xs border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
                   placeholder="Expiry (days)" />
        </div>

        <button wire:click="inviteByEmail"
                class="w-full px-2 py-1.5 text-xs font-medium bg-indigo-600 hover:bg-indigo-700 text-white rounded">
            Invite
        </button>
    </div>

    <div class="space-y-2 border border-gray-200 dark:border-gray-700 rounded-lg p-2">
        <div class="text-xs font-medium text-gray-700 dark:text-gray-300">Shared Users</div>
        @forelse($sharedUsers as $user)
            <div class="flex items-center gap-1">
                <div class="min-w-0 flex-1">
                    <div class="text-xs text-gray-800 dark:text-gray-200 truncate">{{ $user['name'] }}</div>
                    <div class="text-[11px] text-gray-500 dark:text-gray-400 truncate">{{ $user['email'] }}</div>
                </div>
                <select aria-label="Permission for {{ $user['name'] }}" wire:change="updatePermission({{ $user['id'] }}, $event.target.value)"
                        class="text-xs px-1 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                    @foreach(['view','comment','edit','admin'] as $perm)
                        <option value="{{ $perm }}" {{ ($user['pivot']['permission'] ?? 'view') === $perm ? 'selected' : '' }}>{{ ucfirst($perm) }}</option>
                    @endforeach
                </select>
                <button wire:click="revokeUser({{ $user['id'] }})" class="text-[11px] px-1.5 py-1 border border-red-300 text-red-600 rounded">Revoke</button>
            </div>
        @empty
            <div class="text-xs text-gray-500 dark:text-gray-400">No shared users yet.</div>
        @endforelse
    </div>

    <div class="space-y-2 border border-gray-200 dark:border-gray-700 rounded-lg p-2">
        <div class="text-xs font-medium text-gray-700 dark:text-gray-300">Pending Invitations</div>
        @forelse($pendingInvites as $invite)
            <div class="space-y-1 rounded border border-gray-200 dark:border-gray-700 p-2">
                <div class="text-xs text-gray-800 dark:text-gray-200">{{ $invite['email'] }} ({{ $invite['permission'] }})</div>
                <div class="text-[11px] text-gray-500 dark:text-gray-400">Expires: {{ $invite['expires_at'] ?? 'never' }}</div>
                <div class="text-[11px] break-all text-blue-700 dark:text-blue-300">{{ $invite['accept_url'] }}</div>
                <button wire:click="revokeInvitation({{ $invite['id'] }})" class="text-[11px] px-1.5 py-1 border border-red-300 text-red-600 rounded">Revoke Invite</button>
            </div>
        @empty
            <div class="text-xs text-gray-500 dark:text-gray-400">No pending invitations.</div>
        @endforelse
    </div>

    <div class="space-y-2 border border-gray-200 dark:border-gray-700 rounded-lg p-2">
        <div class="text-xs font-medium text-gray-700 dark:text-gray-300">Public View-Only Link</div>
        <label for="livewire-spreadsheet-sharing-panel-publicexpiresat" class="flex items-center gap-2 text-xs text-gray-700 dark:text-gray-300">
            <input type="checkbox" wire:model="publicEnabled" /> Enable public link
        </label>
        <input id="livewire-spreadsheet-sharing-panel-publicexpiresat" type="datetime-local" wire:model="publicExpiresAt"
               class="w-full px-2 py-1.5 text-xs border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white" />

        @if($publicUrl)
            <div class="text-[11px] break-all text-blue-700 dark:text-blue-300">{{ $publicUrl }}</div>
        @endif

        <div class="grid grid-cols-3 gap-1">
            <button wire:click="savePublicLinkSettings" class="text-[11px] px-1.5 py-1 border border-gray-300 dark:border-gray-600 rounded text-gray-700 dark:text-gray-300">Save</button>
            <button wire:click="regeneratePublicToken" class="text-[11px] px-1.5 py-1 border border-amber-300 text-amber-700 rounded">Regenerate</button>
            <button wire:click="disablePublicLink" class="text-[11px] px-1.5 py-1 border border-red-300 text-red-600 rounded">Disable</button>
        </div>
    </div>
</div>
