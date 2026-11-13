{{-- R-50: the company does not hold Dot.Sheet. No workbook, no company data; one action (InfoDot's page for this
     platform) and, for a signed-in person with other companies, a way to move to one of them. --}}
@extends('errors._ecosystem-layout', ['code' => 403])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#12231f" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="4.5" width="17" height="15" rx="2"/><path d="M3.5 9.5h17M9 9.5v10"/><path d="M13 13l4 4M17 13l-4 4" stroke="#b5791a"/></svg>
@endsection

{{-- The header's Dashboard button would only lead back to this page. --}}
@section('nav')
    @auth
        <form method="POST" action="{{ route('logout') }}" style="margin: 0;">
            @csrf
            <button type="submit" class="link-underline" style="background-color: transparent; border: 0; padding: 0 0 1px; cursor: pointer; color: rgba(255,255,255,0.85); font: inherit; font-size: 14px;">Sign out</button>
        </form>
    @endauth
@endsection

@section('title', $title)
@section('message', $message)

@section('actions')
    <a href="{{ $platformsUrl }}" class="press" style="padding: 12px 24px; background: var(--accent); color: #12231f; font-family: var(--font-display); font-weight: 600; font-size: 15px; border-radius: 999px; text-decoration: none;">Open Platforms in InfoDot</a>

    @if ($others->isNotEmpty())
        <div style="flex-basis: 100%; margin-top: 28px;">
            <p style="font-size: 14px; color: var(--ink-soft); margin: 0 0 12px;">Or carry on in another of your workspaces</p>
            <div style="display: flex; flex-wrap: wrap; justify-content: center; gap: 8px;">
                @foreach ($others as $other)
                    <form method="POST" action="{{ route('current-team.update') }}" style="margin: 0;">
                        @method('PUT')
                        @csrf
                        <input type="hidden" name="team_id" value="{{ $other->id }}">
                        <button type="submit" class="press" style="padding: 9px 18px; background: #ffffff; border: 1px solid var(--line); border-radius: 999px; cursor: pointer; color: var(--ink); font: inherit; font-size: 14px; font-weight: 500;">{{ $other->name }}</button>
                    </form>
                @endforeach
            </div>
        </div>
    @endif
@endsection
