<x-app-layout>

<div style="padding:2rem 2.5rem 3rem;max-width:1100px;">

    <div style="margin-bottom:2rem;">
        <h1 style="font-family:'Manrope',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 0.2rem;letter-spacing:-0.01em;">Backup Pruning Queue</h1>
        <p style="font-size:0.78rem;color:#71717a;margin:0;">Backups past the retention window, awaiting your approval to delete</p>
    </div>

    <livewire:backup-proposal-queue />

</div>

</x-app-layout>
